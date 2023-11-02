<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use BigQueryTransformation\BigQueryConnection;
use BigQueryTransformation\Traits\GetEnvVarsTrait;
use Google\Cloud\BigQuery\Exception\JobException;
use Google\Cloud\Core\Exception\ServiceException;
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
            self::TIMEOUT_RECURSIVE_QUERY
        );
    }

    public function testRecursiveQueryWithoutTimeout(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('Job did not complete within the allowed number of retries.');

        $connection = new BigQueryConnection($this->getEnvVars(), $this->getRunIdEnvVar());

        // long-running query
        $connection->executeQuery(
            self::TIMEOUT_RECURSIVE_QUERY
        );
    }
}
