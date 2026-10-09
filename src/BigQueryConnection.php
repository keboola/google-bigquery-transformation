<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use BigQueryTransformation\Client\Retry;
use Google\Auth\HttpHandler\Guzzle6HttpHandler;
use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
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
                    $attempt = func_get_arg(1);
                    if ($ex instanceof Throwable) {
                        return Retry::shouldRetryException($ex, is_int($attempt) ? $attempt : 0);
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

        $startedAt = microtime(true);
        $job = $this->client->startQuery(
            $this->client->query($query, $queryOptions)->defaultDataset($this->dataset),
        );

        // Poll jobs.get, not getQueryResults — on runtime errors (e.g. missing GCS files)
        // the latter keeps jobComplete=false forever.
        (new JobPoller($this->queryTimeout, $this->maxPollRetries, $this->logger))
            ->waitUntilDone($job, $startedAt);

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
            throw new UserException($this->formatErrorResult($errorResult));
        }

        return $job->queryResults();
    }

    /**
     * @param array<string, string> $errorResult
     */
    private function formatErrorResult(array $errorResult): string
    {
        $parts = array_filter([
            $errorResult['message'] ?? null,
            isset($errorResult['reason']) ? sprintf('(reason: %s)', $errorResult['reason']) : null,
            isset($errorResult['location']) ? sprintf('(at %s)', $errorResult['location']) : null,
        ]);
        return $parts ? implode(' ', $parts) : 'BigQuery job failed';
    }
}
