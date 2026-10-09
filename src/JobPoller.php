<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use BigQueryTransformation\Exception\ApplicationException;
use Closure;
use Google\Cloud\BigQuery\Job;
use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Core\ExponentialBackoff;
use Keboola\Component\UserException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Polls jobs.get until the job is DONE. Unlike Job::waitUntilComplete(), the wait
 * is bounded by query_timeout (+ grace) and max_poll_retries; poll errors are retried.
 */
class JobPoller
{
    public const DEADLINE_GRACE_SECONDS = 60;
    private const POLL_REQUEST_TIMEOUT_SECONDS = 120;
    private const CANCEL_REQUEST_TIMEOUT_SECONDS = 10;

    private Closure $clock;

    private Closure $sleep;

    /**
     * @param (Closure(): float)|null $clock returns current time in seconds
     * @param (Closure(float): void)|null $sleep sleeps for given number of seconds
     */
    public function __construct(
        private readonly int $queryTimeout,
        private readonly int $maxPollRetries,
        private readonly ?LoggerInterface $logger = null,
        ?Closure $clock = null,
        ?Closure $sleep = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1000000));
        };
    }

    /**
     * @throws \Keboola\Component\UserException
     * @throws \BigQueryTransformation\Exception\ApplicationException
     */
    public function waitUntilDone(Job $job, float $startedAt): void
    {
        if ($job->isComplete()) {
            return;
        }

        $deadline = $this->queryTimeout > 0
            ? $startedAt + $this->queryTimeout + self::DEADLINE_GRACE_SECONDS
            : null;
        $lastError = null;
        $attempt = 0;
        while (true) {
            $remaining = $deadline === null ? null : $deadline - ($this->clock)();
            if ($remaining !== null && $remaining <= 0) {
                $this->cancel($job);
                if ($lastError !== null) {
                    throw new ApplicationException(
                        sprintf(
                            'Unable to get status of BigQuery job "%s" within the query timeout (%d s). '
                            . 'Last error: %s',
                            $this->jobId($job),
                            $this->queryTimeout,
                            $lastError->getMessage(),
                        ),
                        0,
                        $lastError,
                    );
                }
                throw new UserException('Query exceeded the maximum execution time');
            }

            try {
                $info = $job->reload([
                    // retried by this loop, so a poll cannot outlive the deadline
                    'retries' => 0,
                    'requestTimeout' => $remaining === null
                        ? self::POLL_REQUEST_TIMEOUT_SECONDS
                        : max(1, min(self::POLL_REQUEST_TIMEOUT_SECONDS, (int) ceil($remaining))),
                ]);
                $lastError = null;
                if (($info['status']['state'] ?? null) === 'DONE') {
                    return;
                }
            } catch (GoogleException $e) {
                $lastError = $e;
                $this->logger?->warning(sprintf(
                    'Polling status of BigQuery job "%s" failed (attempt %d): %s',
                    $this->jobId($job),
                    $attempt + 1,
                    $e->getMessage(),
                ));
            }

            if ($this->maxPollRetries > 0 && $attempt >= $this->maxPollRetries) {
                if ($lastError !== null) {
                    throw new ApplicationException(
                        sprintf(
                            'Unable to get status of BigQuery job "%s" within %d polling attempts. Last error: %s',
                            $this->jobId($job),
                            $attempt + 1,
                            $lastError->getMessage(),
                        ),
                        0,
                        $lastError,
                    );
                }
                throw new UserException(
                    'BigQuery job did not complete within the allowed polling window; '
                    . 'the query may be stuck or the BigQuery API unreachable.',
                );
            }

            $delay = ExponentialBackoff::calculateDelay($attempt) / 1000000;
            if ($deadline !== null) {
                $delay = min($delay, max(0.0, $deadline - ($this->clock)()));
            }
            ($this->sleep)($delay);
            $attempt++;
        }
    }

    private function cancel(Job $job): void
    {
        try {
            // best effort, no retries
            $job->cancel(['retries' => 0, 'requestTimeout' => self::CANCEL_REQUEST_TIMEOUT_SECONDS]);
        } catch (Throwable $e) {
            $this->logger?->warning(sprintf(
                'Cancelling BigQuery job "%s" failed: %s',
                $this->jobId($job),
                $e->getMessage(),
            ));
        }
    }

    private function jobId(Job $job): string
    {
        return (string) ($job->identity()['jobId'] ?? 'unknown');
    }
}
