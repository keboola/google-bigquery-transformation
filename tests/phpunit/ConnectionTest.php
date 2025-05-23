<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use BigQueryTransformation\BigQueryConnection;
use BigQueryTransformation\Traits\GetEnvVarsTrait;
use Google\Cloud\Core\Exception\BadRequestException;
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
        $this->expectException(BadRequestException::class);

        $connection = new BigQueryConnection($this->getEnvVars(), $this->getRunIdEnvVar());

        // long-running query
        $connection->executeQuery(
            self::TIMEOUT_RECURSIVE_QUERY,
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
