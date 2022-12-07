<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use BigQueryTransformation\BigQueryConnection;
use BigQueryTransformation\Exception\ApplicationException;
use Google\Cloud\Core\Exception\ServiceException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class ConnectionTest extends TestCase
{
    /**
     * @throws \JsonException
     */
    public function testConnection(): void
    {
        try {
            $connection = new BigQueryConnection($this->getCredentialsJson());
            $connection->executeQuery('SELECT 1');
        } catch (Throwable $e) {
            $this->fail($e->getMessage());
        }

        $this->expectNotToPerformAssertions();
    }

    /**
     * @throws \JsonException
     */
    public function testConnectionWrongCredentials(): void
    {
        /** @var array<string, string> $credentialsArray */
        $credentialsArray = json_decode($this->getCredentialsJson(), true);
        unset($credentialsArray['private_key']);

            $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('json key is missing the private_key field');

        $connection = new BigQueryConnection(json_encode($credentialsArray, JSON_THROW_ON_ERROR));
        $connection->executeQuery('SELECT 1');
    }

    /**
     * @throws \JsonException
     */
    public function testConnectionInvalidCredentialsJson(): void
    {
        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('Invalid JSON with BigQuery credentials');

        $connection = new BigQueryConnection('');
        $connection->executeQuery('SELECT 1');
    }

    protected function getCredentialsJson(): string
    {
        $credentialsJson = getenv('BQ_CREDENTIALS');
        if (!$credentialsJson) {
            throw new RuntimeException('Missing "BQ_CREDENTIALS" environment variable!');
        }

        return $credentialsJson;
    }
}
