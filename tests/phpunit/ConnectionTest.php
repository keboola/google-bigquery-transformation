<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use BigQueryTransformation\BigQueryConnection;
use BigQueryTransformation\Exception\ApplicationException;
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

    /**
     * @throws \JsonException
     * @throws \BigQueryTransformation\Exception\ApplicationException
     */
    public function testConnectionWrongCredentials(): void
    {
        $configArray = $this->getEnvVars();

        /** @var array<string, string> $credentialsArray */
        $credentialsArray = json_decode($configArray['credentials'], true);
        unset($credentialsArray['private_key']);
        $configArray['credentials'] = json_encode($credentialsArray, JSON_THROW_ON_ERROR);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('json key is missing the private_key field');

        $connection = new BigQueryConnection($configArray);
        $connection->executeQuery('SELECT 1');
    }

    /**
     * @throws \JsonException
     */
    public function testConnectionInvalidCredentialsJson(): void
    {
        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('Invalid JSON with BigQuery credentials');

        $configArray = $this->getEnvVars();
        $configArray['credentials'] = '';

        $connection = new BigQueryConnection($configArray);
        $connection->executeQuery('SELECT 1');
    }
}
