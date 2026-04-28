<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use BigQueryTransformation\Client\Retry;
use Google\Auth\HttpHandler\Guzzle6HttpHandler;
use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
use Google\Cloud\BigQuery\Exception\JobException;
use Google\Cloud\BigQuery\QueryResults;
use Google\Cloud\Core\ClientTrait;
use Google\Cloud\Core\Exception\ServiceException;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Keboola\Component\UserException;
use Keboola\TableBackendUtils\Connection\Bigquery\Session;
use Keboola\TableBackendUtils\Connection\Bigquery\SessionFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class BigQueryConnection
{
    use ClientTrait;

    private BigQueryClient $client;

    private Dataset $dataset;

    private Session $session;

    private string $runId;

    /**
     * @param array<string, string|array<string, string>> $databaseConfig
     */
    public function __construct(
        array $databaseConfig,
        string $runId,
        private readonly int $queryTimeout = 0,
        ?HandlerStack $handlerStack = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $maxPollRetries = 0,
    ) {
        if ($handlerStack === null) {
            $handlerStack = HandlerStack::create();
        }
        $guzzleClient = new Client(['handler' => $handlerStack]);

        $this->client = new BigQueryClient([
            'keyFile' => $databaseConfig['credentials'],
            'httpHandler' => new Guzzle6HttpHandler($guzzleClient),
            'restRetryFunction' => function () {
                // BigQuery client sometimes calls directly restRetryFunction with exception as first argument
                // But in other cases it expects to return callable which accepts exception as first argument
                $argsNum = func_num_args();
                if ($argsNum === 2) {
                    $ex = func_get_arg(0);
                    if ($ex instanceof Throwable) {
                        return Retry::shouldRetryException($ex);
                    }
                }
                return [Retry::class, 'shouldRetryException'];
            },
            'restOptions' => [
                'headers' => [
                    'User-Agent' => 'Keboola/1.0 (GPN:Keboola; connection)',
                ],
            ],
            'retries' => 30,
            'requestTimeout' => 120,
            'location' => $databaseConfig['region'],
        ]);
        $this->session = (new SessionFactory($this->client))->createSession();
        /** @var string $schema */
        $schema = $databaseConfig['schema'];
        $this->dataset = $this->client->dataset($schema);
        $this->runId = $runId;
    }

    public function getClient(): BigQueryClient
    {
        return $this->client;
    }

    /**
     * @throws \Keboola\Component\UserException
     * @throws \Google\Cloud\Core\Exception\ServiceException
     */
    public function executeQuery(string $query): QueryResults
    {
        $queryOptions = $this->session->getAsQueryOptions();
        $queryOptions['configuration']['labels'] = ['run_id' => $this->runId];

        $branchId = getenv('KBC_BRANCHID');
        if ($branchId) {
            $queryOptions['configuration']['labels']['branch_id'] = $branchId;
        }

        if ($this->queryTimeout !== 0) {
            $queryOptions['configuration']['jobTimeoutMs'] = $this->queryTimeout * 1000;
        }

        $formattedLabels = [];
        foreach ($queryOptions['configuration']['labels'] as $key => $value) {
            $formattedLabels[] = "$key: $value";
        }
        $this->logger?->debug(
            sprintf(
                'Executing query with labels: %s',
                implode(', ', $formattedLabels),
            ),
        );

        $waitOptions = [];
        if ($this->maxPollRetries > 0) {
            $waitOptions['maxRetries'] = $this->maxPollRetries;
        }

        $startedAt = microtime(true);
        $job = $this->client->startQuery(
            $this->client->query($query, $queryOptions)->defaultDataset($this->dataset),
        );

        try {
            // Poll jobs.get (status.state) instead of jobs.getQueryResults (jobComplete).
            // For runtime errors such as "Not found: Files gs://…", BigQuery keeps
            // jobComplete=false on getQueryResults indefinitely while the job itself
            // reaches state=DONE — using Job::waitUntilComplete avoids the silent hang.
            $job->waitUntilComplete($waitOptions);
        } catch (JobException $e) {
            throw new UserException(
                'BigQuery job did not complete within the allowed polling window; '
                . 'the query may be stuck or the BigQuery API unreachable. '
                . 'Original error: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $this->logger?->debug(sprintf(
            'BigQuery job %s finished in %.1fs',
            $job->identity()['jobId'] ?? 'unknown',
            microtime(true) - $startedAt,
        ));

        $errorResult = $job->info()['status']['errorResult'] ?? null;
        if (is_array($errorResult)) {
            $errorMessage = (string) ($errorResult['message'] ?? '');
            if (str_contains($errorMessage, 'Job timed out after')) {
                throw new UserException('Query exceeded the maximum execution time');
            }
            throw new UserException($errorMessage !== '' ? $errorMessage : 'BigQuery job failed');
        }

        return $job->queryResults();
    }
}
