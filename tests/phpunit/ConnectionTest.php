<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use BigQueryTransformation\BigQueryConnection;
use BigQueryTransformation\Traits\GetEnvVarsTrait;
use Google\Cloud\Core\Exception\ServiceException;
use PHPUnit\Framework\TestCase;
use Throwable;

class ConnectionTest extends TestCase
{
    use GetEnvVarsTrait;

    public function testConnection(): void
    {
        try {
            $connection = new BigQueryConnection($this->getEnvVars());
            $connection->executeQuery('SELECT 1');
        } catch (Throwable $e) {
            $this->fail($e->getMessage());
        }

        $this->expectNotToPerformAssertions();
    }

    public function testConnectionWrongCredentials(): void
    {
        $configArray = $this->getEnvVars();
        unset($configArray['credentials']['private_key']);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('json key is missing the private_key field');

        $connection = new BigQueryConnection($configArray);
        $connection->executeQuery('SELECT 1');
    }
}
