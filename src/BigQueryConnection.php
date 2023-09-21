<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
use Google\Cloud\BigQuery\QueryResults;
use Google\Cloud\Core\Exception\ServiceException;
use Keboola\Component\UserException;
use Keboola\TableBackendUtils\Connection\Bigquery\Session;
use Keboola\TableBackendUtils\Connection\Bigquery\SessionFactory;

class BigQueryConnection
{
    private BigQueryClient $client;
    private Dataset $dataset;
    private Session $session;
    private string $runId;

    /**
     * @param array<string, string|array<string, string>> $databaseConfig
     */
    public function __construct(array $databaseConfig, string $runId, int $queryTimeout = 0)
    {
        $this->client = new BigQueryClient([
            'keyFile' => $databaseConfig['credentials'],
            'requestTimeout' => $queryTimeout,
        ]);
        $this->session = (new SessionFactory($this->client))->createSession();
        /** @var string $schema */
        $schema = $databaseConfig['schema'];
        $this->dataset = $this->client->dataset($schema);
        $this->runId = $runId;
    }

    /**
     * @throws \Keboola\Component\UserException
     * @throws \Google\Cloud\Core\Exception\ServiceException
     */
    public function executeQuery(string $query): QueryResults
    {
        $queryOptions = $this->session->getAsQueryOptions();
        $queryOptions['configuration']['labels'] = ['run_id' => $this->runId];

        try {
            return $this->client->runQuery($this->client->query($query, $queryOptions)->defaultDataset($this->dataset));
        } catch (ServiceException $e) {
            if (str_contains($e->getMessage(), 'Operation timed out')) {
                throw new UserException('Query exceeded the maximum execution time');
            }
            throw $e;
        }
    }
}
