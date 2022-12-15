<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use BigQueryTransformation\Exception\ApplicationException;
use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
use Google\Cloud\BigQuery\QueryResults;
use JsonException;
use Keboola\Component\BaseComponent;

class BigQueryConnection extends BaseComponent
{
    private BigQueryClient $client;
    private Dataset $dataset;

    /**
     * @param array<string, string> $databaseConfig
     * @throws \BigQueryTransformation\Exception\ApplicationException
     */
    public function __construct(array $databaseConfig)
    {
        try {
            $credentials = (array) json_decode(
                $databaseConfig['credentials'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->client = new BigQueryClient(['keyFile' => $credentials]);
        } catch (JsonException $e) {
            throw new ApplicationException('Invalid JSON with BigQuery credentials');
        }

        $this->dataset = $this->client->dataset($databaseConfig['schema']);
    }

    public function executeQuery(string $query): QueryResults
    {
        return $this->client->runQuery($this->client->query($query)->defaultDataset($this->dataset));
    }
}
