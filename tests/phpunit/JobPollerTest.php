<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use BigQueryTransformation\JobPoller;
use Google\Cloud\BigQuery\Job;
use Google\Cloud\Core\Exception\ServiceException;
use Keboola\Component\UserException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class JobPollerTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('@1000');
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    private function createPoller(int $queryTimeout, int $maxPollRetries = 0): JobPoller
    {
        return new JobPoller($queryTimeout, $maxPollRetries, null, $this->clock);
    }

    /**
     * @param list<bool|ServiceException> $pollResults result of each reload: completion state or thrown exception
     * @return Job&MockObject
     */
    private function createJob(bool $initiallyComplete, array $pollResults): Job
    {
        $complete = $initiallyComplete;
        $job = $this->createMock(Job::class);
        $job->method('identity')->willReturn(['jobId' => 'job-1']);
        $job->method('isComplete')->willReturnCallback(function () use (&$complete): bool {
            return $complete;
        });
        $job->method('reload')->willReturnCallback(function () use (&$complete, &$pollResults): array {
            $result = array_shift($pollResults) ?? false;
            if ($result instanceof ServiceException) {
                throw $result;
            }
            $complete = $result;
            return ['status' => ['state' => $complete ? 'DONE' : 'RUNNING']];
        });
        return $job;
    }

    public function testAlreadyCompleteJobIsNotPolled(): void
    {
        $job = $this->createJob(true, []);
        $job->expects($this->never())->method('reload');

        $this->createPoller(10)->waitUntilDone($job, $this->now());
    }

    public function testPollsUntilDone(): void
    {
        $job = $this->createJob(false, [false, false, true]);
        $job->expects($this->exactly(3))->method('reload');
        $job->expects($this->never())->method('cancel');

        $this->createPoller(0)->waitUntilDone($job, $this->now());
    }

    public function testRecoversFromPollErrors(): void
    {
        $job = $this->createJob(false, [
            new ServiceException('Request had invalid authentication credentials', 401),
            new ServiceException('Request had invalid authentication credentials', 401),
            true,
        ]);
        $job->expects($this->exactly(3))->method('reload');

        $this->createPoller(600)->waitUntilDone($job, $this->now());
    }

    public function testPollRequestsDoNotRetryAndRespectRemainingTime(): void
    {
        $startedAt = $this->now();
        $job = $this->createJob(false, []);
        $job->expects($this->atLeastOnce())
            ->method('reload')
            ->with($this->callback(function (array $options) use ($startedAt): bool {
                $remaining = $startedAt + 30 + JobPoller::DEADLINE_GRACE_SECONDS - $this->now();
                return $options['retries'] === 0
                    && $options['requestTimeout'] >= 1
                    && $options['requestTimeout'] <= max(1, (int) ceil($remaining));
            }));

        $this->expectException(UserException::class);
        $this->createPoller(30)->waitUntilDone($job, $startedAt);
    }

    public function testRunningJobExceedingTimeoutIsCancelled(): void
    {
        $startedAt = $this->now();
        $job = $this->createJob(false, []);
        $job->expects($this->once())
            ->method('cancel')
            ->with($this->callback(fn(array $options): bool => $options['retries'] === 0
                && $options['requestTimeout'] <= 10));

        try {
            $this->createPoller(30)->waitUntilDone($job, $startedAt);
            self::fail('Expected timeout.');
        } catch (UserException $e) {
            self::assertSame('Query exceeded the maximum execution time.', $e->getMessage());
        }
        self::assertEqualsWithDelta($startedAt + 30 + JobPoller::DEADLINE_GRACE_SECONDS, $this->now(), 0.001);
    }

    public function testPersistentPollErrorsFailAtTimeoutWithLastError(): void
    {
        $startedAt = $this->now();
        $job = $this->createJob(false, array_fill(
            0,
            1000,
            new ServiceException('Request had invalid authentication credentials', 401),
        ));
        $job->expects($this->once())->method('cancel');

        try {
            $this->createPoller(300)->waitUntilDone($job, $startedAt);
            self::fail('Expected timeout.');
        } catch (UserException $e) {
            self::assertSame(
                'Query exceeded the maximum execution time (status of job "job-1" could not be retrieved: '
                . 'Request had invalid authentication credentials).',
                $e->getMessage(),
            );
            self::assertInstanceOf(ServiceException::class, $e->getPrevious());
        }
        self::assertEqualsWithDelta($startedAt + 300 + JobPoller::DEADLINE_GRACE_SECONDS, $this->now(), 0.001);
    }

    public function testCancelFailureDoesNotHideTimeout(): void
    {
        $job = $this->createJob(false, []);
        $job->method('cancel')->willThrowException(new ServiceException('cancel failed', 500));

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Query exceeded the maximum execution time');
        $this->createPoller(5)->waitUntilDone($job, $this->now());
    }

    public function testMaxPollRetriesExhausted(): void
    {
        $job = $this->createJob(false, []);
        $job->expects($this->exactly(3))->method('reload');

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('BigQuery job did not complete within the allowed polling window');
        $this->createPoller(0, 2)->waitUntilDone($job, $this->now());
    }

    public function testMaxPollRetriesExhaustedOnErrorsIncludesLastError(): void
    {
        $job = $this->createJob(false, array_fill(0, 3, new ServiceException('Backend error', 500)));

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('(status of job "job-1" could not be retrieved: Backend error).');
        $this->createPoller(0, 2)->waitUntilDone($job, $this->now());
    }

    /**
     * @return iterable<string, array{ServiceException}>
     */
    public function permanentPollErrorProvider(): iterable
    {
        yield 'bad request' => [new ServiceException('Invalid job ID', 400)];
        yield 'forbidden' => [new ServiceException('Access Denied', 403)];
        yield 'not found' => [new ServiceException('Not found: Job job-1', 404)];
    }

    /**
     * @dataProvider permanentPollErrorProvider
     */
    public function testPermanentPollErrorFailsFast(ServiceException $error): void
    {
        $job = $this->createJob(false, [$error, true]);
        $job->expects($this->once())->method('reload');
        $job->expects($this->once())->method('cancel');

        try {
            // no timeout and no retry limit: without failing fast this would poll forever
            $this->createPoller(0)->waitUntilDone($job, $this->now());
            self::fail('Expected failure.');
        } catch (UserException $e) {
            self::assertSame(
                sprintf(
                    'Polling status of BigQuery job failed (status of job "job-1" could not be retrieved: %s).',
                    $error->getMessage(),
                ),
                $e->getMessage(),
            );
            self::assertSame($error, $e->getPrevious());
        }
    }

    public function testRateLimitedPollIsRetried(): void
    {
        $rateLimited = new ServiceException(
            (string) json_encode(['error' => [
                'code' => 403,
                'message' => 'Exceeded rate limits',
                'errors' => [['reason' => 'rateLimitExceeded']],
            ]]),
            403,
        );
        $job = $this->createJob(false, [$rateLimited, true]);
        $job->expects($this->exactly(2))->method('reload');
        $job->expects($this->never())->method('cancel');

        $this->createPoller(0)->waitUntilDone($job, $this->now());
    }

    public function testPollTimeoutIsRetried(): void
    {
        $job = $this->createJob(false, [new ServiceException('cURL error 28: Operation timed out', 0), true]);
        $job->expects($this->exactly(2))->method('reload');

        $this->createPoller(0)->waitUntilDone($job, $this->now());
    }
}
