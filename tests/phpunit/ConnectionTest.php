<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use BigQueryTransformation\BigQueryConnection;
use BigQueryTransformation\Traits\GetEnvVarsTrait;
use Google\Cloud\Core\Exception\ServiceException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Keboola\Component\UserException;
use PHPUnit\Framework\TestCase;
use Throwable;

class ConnectionTest extends TestCase
{
    use GetEnvVarsTrait;

    private const TIMEOUT_RECURSIVE_QUERY = 'WITH RECURSIVE counter AS (
                      SELECT 1 AS n
                      UNION ALL
                      SELECT n+1 FROM counter WHERE n < 10000
                    )

                    SELECT 
                      a.n AS val1, 
                      b.n AS val2
                    FROM 
                      counter a 
                    CROSS JOIN 
                      counter b;';

    public function testConnection(): void
    {
        try {
            $connection = new BigQueryConnection($this->getEnvVars(), $this->getRunIdEnvVar());
            $connection->executeQuery('SELECT 1');
        } catch (Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    public function testConnectionWrongCredentials(): void
    {
        $configArray = $this->getEnvVars();
        unset($configArray['credentials']['private_key']);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('json key is missing the private_key field');

        $connection = new BigQueryConnection($configArray, $this->getRunIdEnvVar());
        $connection->executeQuery('SELECT 1');
    }

    public function testQueryTimeout(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Query exceeded the maximum execution time');

        $connection = new BigQueryConnection($this->getEnvVars(), $this->getRunIdEnvVar(), 1);

        // long-running query
        $connection->executeQuery(
            self::TIMEOUT_RECURSIVE_QUERY,
        );
    }

    public function testRecursiveQueryWithoutTimeout(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessageMatches('/recursive CTE has reached the maximum number of iterations/');

        $connection = new BigQueryConnection($this->getEnvVars(), $this->getRunIdEnvVar());

        // BigQuery completes the job with state=DONE and an embedded errorResult;
        // executeQuery surfaces it via the formatted UserException path.
        $connection->executeQuery(
            self::TIMEOUT_RECURSIVE_QUERY,
        );
    }

    public function testPollRetriesExhausted(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('BigQuery job did not complete within the allowed polling window');

        $connection = new BigQueryConnection(
            $this->getEnvVars(),
            $this->getRunIdEnvVar(),
            0,
            null,
            null,
            1,
        );

        // long-running query with maxPollRetries=1 so the second incomplete poll
        // bubbles up as JobException → UserException within a few seconds
        $connection->executeQuery(
            self::TIMEOUT_RECURSIVE_QUERY,
        );
    }

    public function testPollingUsesJobsGetEndpoint(): void
    {
        // Polling must hit jobs.get (state=DONE), not jobs.getQueryResults
        // (jobComplete) — the latter returns jobComplete=false indefinitely
        // for jobs that fail with runtime errors such as missing GCS files.
        // Trivial queries can complete without entering the polling loop, so
        // exercise a query that is guaranteed to require at least one poll.
        $historyContainer = [];
        $historyMiddleware = Middleware::history($historyContainer);
        $handlerStack = HandlerStack::create();
        $handlerStack->push($historyMiddleware);

        $connection = new BigQueryConnection($this->getEnvVars(), $this->getRunIdEnvVar(), 0, $handlerStack);
        try {
            $connection->executeQuery(self::TIMEOUT_RECURSIVE_QUERY);
            self::fail('Expected the recursive query to fail with an errorResult.');
        } catch (UserException) {
            // expected — the recursive CTE exhausts BigQuery's iteration limit
        }

        $capturedPaths = [];
        $jobsGetCalled = false;
        foreach ($historyContainer as $transaction) {
            /** @var Request $request */
            $request = $transaction['request'];
            if ($request->getMethod() !== 'GET') {
                continue;
            }
            $path = $request->getUri()->getPath();
            $capturedPaths[] = $path;
            if (preg_match('#/projects/[^/]+/jobs/[^/]+$#', $path) === 1) {
                $jobsGetCalled = true;
                break;
            }
        }

        self::assertTrue(
            $jobsGetCalled,
            'executeQuery did not poll the jobs.get endpoint; '
            . 'polling appears to use a different endpoint and may hang on runtime errors. '
            . 'Captured GET paths: ' . implode(', ', $capturedPaths),
        );
    }

    public function testUserAgent(): void
    {
        $historyContainer = [];
        $historyMiddleware = Middleware::history($historyContainer);
        $handlerStack = HandlerStack::create();
        $handlerStack->push($historyMiddleware);

        $connection = new BigQueryConnection($this->getEnvVars(), $this->getRunIdEnvVar(), 0, $handlerStack);
        $connection->executeQuery('SELECT 1');

        $this->assertNotEmpty($historyContainer, 'No requests were captured.');
        foreach ($historyContainer as $transaction) {
            /** @var Request $request */
            $request = $transaction['request'];
            $headers = $request->getHeaders();

            $this->assertArrayHasKey('User-Agent', $headers, 'User-Agent header is missing.');
            $this->assertEquals(
                'Keboola/1.0 (GPN:Keboola; connection)',
                $headers['User-Agent'][0],
                'User-Agent header is incorrect.',
            );
        }
    }
    public function testBranchIdLabel(): void
    {
        // Set branch ID environment variable
        putenv('KBC_BRANCHID=test-branch');

        $historyContainer = [];
        $historyMiddleware = Middleware::history($historyContainer);
        $handlerStack = HandlerStack::create();
        $handlerStack->push($historyMiddleware);

        $connection = new BigQueryConnection($this->getEnvVars(), $this->getRunIdEnvVar(), 0, $handlerStack);
        $connection->executeQuery('SELECT 1');

        // Clean up environment variable
        putenv('KBC_BRANCHID');

        $this->assertNotEmpty($historyContainer, 'No requests were captured.');

        $branchIdFound = false;
        foreach ($historyContainer as $transaction) {
            $request = $transaction['request'];
            $requestBody = (string) $request->getBody();

            // Check if branch_id label is in the request body
            if (strpos($requestBody, '"branch_id":"test-branch"') !== false) {
                $branchIdFound = true;
                break;
            }
        }

        $this->assertTrue($branchIdFound, 'branch_id label was not found in the request body');
    }
}
