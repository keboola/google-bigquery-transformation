<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use BigQueryTransformation\Exception\ApplicationException;
use BigQueryTransformation\JobPoller;
use Google\Cloud\BigQuery\Job;
use Google\Cloud\Core\Exception\ServiceException;
use Keboola\Component\UserException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class JobPollerTest extends TestCase
{
    private float $now = 1000.0;

    private function createPoller(int $queryTimeout, int $maxPollRetries = 0): JobPoller
    {
        return new JobPoller(
            $queryTimeout,
            $maxPollRetries,
            null,
            fn(): float => $this->now,
            function (float $seconds): void {
                $this->now += $seconds;
            },
        );
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

        $this->createPoller(10)->waitUntilDone($job, $this->now);
    }

    public function testPollsUntilDone(): void
    {
        $job = $this->createJob(false, [false, false, true]);
        $job->expects($this->exactly(3))->method('reload');
        $job->expects($this->never())->method('cancel');

        $this->createPoller(0)->waitUntilDone($job, $this->now);
    }

    public function testRecoversFromPollErrors(): void
    {
        $job = $this->createJob(false, [
            new ServiceException('Request had invalid authentication credentials', 401),
            new ServiceException('Request had invalid authentication credentials', 401),
            true,
        ]);
        $job->expects($this->exactly(3))->method('reload');

        $this->createPoller(600)->waitUntilDone($job, $this->now);
    }

    public function testPollRequestsDoNotRetryAndRespectRemainingTime(): void
    {
        $startedAt = $this->now;
        $job = $this->createJob(false, []);
        $job->expects($this->atLeastOnce())
            ->method('reload')
            ->with($this->callback(function (array $options) use ($startedAt): bool {
                $remaining = $startedAt + 30 + JobPoller::DEADLINE_GRACE_SECONDS - $this->now;
                return $options['retries'] === 0
                    && $options['requestTimeout'] >= 1
                    && $options['requestTimeout'] <= max(1, (int) ceil($remaining));
            }));

        $this->expectException(UserException::class);
        $this->createPoller(30)->waitUntilDone($job, $startedAt);
    }

    public function testRunningJobExceedingTimeoutIsCancelled(): void
    {
        $startedAt = $this->now;
        $job = $this->createJob(false, []);
        $job->expects($this->once())->method('cancel');

        try {
            $this->createPoller(30)->waitUntilDone($job, $startedAt);
            self::fail('Expected timeout.');
        } catch (UserException $e) {
            self::assertSame('Query exceeded the maximum execution time', $e->getMessage());
        }
        self::assertEqualsWithDelta($startedAt + 30 + JobPoller::DEADLINE_GRACE_SECONDS, $this->now, 0.001);
    }

    public function testPersistentPollErrorsFailAtTimeoutAsApplicationError(): void
    {
        $startedAt = $this->now;
        $job = $this->createJob(false, array_fill(
            0,
            1000,
            new ServiceException('Request had invalid authentication credentials', 401),
        ));
        $job->expects($this->once())->method('cancel');

        try {
            $this->createPoller(300)->waitUntilDone($job, $startedAt);
            self::fail('Expected timeout.');
        } catch (ApplicationException $e) {
            self::assertStringContainsString('within the query timeout (300 s)', $e->getMessage());
            self::assertStringContainsString('Request had invalid authentication credentials', $e->getMessage());
            self::assertInstanceOf(ServiceException::class, $e->getPrevious());
        }
        self::assertEqualsWithDelta($startedAt + 300 + JobPoller::DEADLINE_GRACE_SECONDS, $this->now, 0.001);
    }

    public function testCancelFailureDoesNotHideTimeout(): void
    {
        $job = $this->createJob(false, []);
        $job->method('cancel')->willThrowException(new ServiceException('cancel failed', 500));

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Query exceeded the maximum execution time');
        $this->createPoller(5)->waitUntilDone($job, $this->now);
    }

    public function testMaxPollRetriesExhausted(): void
    {
        $job = $this->createJob(false, []);
        $job->expects($this->exactly(3))->method('reload');

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('BigQuery job did not complete within the allowed polling window');
        $this->createPoller(0, 2)->waitUntilDone($job, $this->now);
    }

    public function testMaxPollRetriesExhaustedOnErrorsIsApplicationError(): void
    {
        $job = $this->createJob(false, array_fill(0, 3, new ServiceException('Forbidden', 403)));

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('within 3 polling attempts. Last error: Forbidden');
        $this->createPoller(0, 2)->waitUntilDone($job, $this->now);
    }
}
