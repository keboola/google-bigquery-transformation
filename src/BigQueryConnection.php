<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
use Google\Cloud\BigQuery\QueryResults;
use Keboola\TableBackendUtils\Connection\Bigquery\Session;
use Keboola\TableBackendUtils\Connection\Bigquery\SessionFactory;

class BigQueryConnection
{
    private BigQueryClient $client;
    private Dataset $dataset;
    private Session $session;

    /**
     * @param array<string, string|array<string, string>> $databaseConfig
     */
    public function __construct(array $databaseConfig)
    {
        $this->client = new BigQueryClient(['keyFile' => $databaseConfig['credentials']]);
        $this->session = (new SessionFactory($this->client))->createSession();
        /** @var string $schema */
        $schema = $databaseConfig['schema'];
        $this->dataset = $this->client->dataset($schema);
    }

    public function executeQuery(string $query): QueryResults
    {
        return $this->client->runQuery(
            $this->client->query($query, $this->session->getAsQueryOptions())->defaultDataset($this->dataset)
        );
    }
}
