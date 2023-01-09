<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
use Google\Cloud\BigQuery\QueryResults;

class BigQueryConnection
{
    private BigQueryClient $client;
    private Dataset $dataset;

    /**
     * @param array<string, string|array<string, string>> $databaseConfig
     */
    public function __construct(array $databaseConfig)
    {
        $this->client = new BigQueryClient(['keyFile' => $databaseConfig['credentials']]);
        /** @var string $schema */
        $schema = $databaseConfig['schema'];
        $this->dataset = $this->client->dataset($schema);
    }

    public function executeQuery(string $query): QueryResults
    {
        return $this->client->runQuery($this->client->query($query)->defaultDataset($this->dataset));
    }
}
