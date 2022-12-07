<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use BigQueryTransformation\Exception\ApplicationException;
use Google\Cloud\BigQuery\BigQueryClient;
use JsonException;
use Keboola\Component\BaseComponent;

class BigQueryConnection extends BaseComponent
{
    private BigQueryClient $client;

    /**
     * @throws \BigQueryTransformation\Exception\ApplicationException
     */
    public function __construct(string $credentials)
    {
        try {
            $credentials = (array) json_decode($credentials, true, 512, JSON_THROW_ON_ERROR);
            $this->client = new BigQueryClient(['keyFile' => $credentials]);
        } catch (JsonException $e) {
            throw new ApplicationException('Invalid JSON with BigQuery credentials');
        }
    }

    public function executeQuery(string $query): void
    {
        $this->client->runQuery($this->client->query($query));
    }
}
