<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Dataset;
use Google\Cloud\BigQuery\QueryResults;
use Google\Cloud\Core\ClientTrait;
use Google\Cloud\Core\Exception\ServiceException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Keboola\Component\UserException;
use Keboola\TableBackendUtils\Connection\Bigquery\Session;
use Keboola\TableBackendUtils\Connection\Bigquery\SessionFactory;
use Throwable;

class BigQueryConnection
{
    use ClientTrait;

    private const RETRY_MISSING_CREATE_JOB = 'bigquery.jobs.create';
    private const RETRY_ON_REASON = [
        'rateLimitExceeded',
        'userRateLimitExceeded',
        'backendError',
        'jobRateLimitExceeded',
    ];

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
        private readonly int $queryTimeout = 0
    ) {
        $this->client = new BigQueryClient([
            'keyFile' => $databaseConfig['credentials'],
            'restRetryFunction' => function () {
                return function (Throwable $ex) {
                    $statusCode = $ex->getCode();

                    if (in_array($statusCode, [429, 500, 503,])) {
                        return true;
                    }
                    if ($statusCode >= 200 && $statusCode < 300) {
                        return false;
                    }

                    $message = $ex->getMessage();
                    if ($ex instanceof RequestException && $ex->hasResponse()) {
                        $message = (string) $ex->getResponse()?->getBody();
                    }

                    try {
                        $message = $this->jsonDecode(
                            $message,
                            true
                        );
                    } catch (InvalidArgumentException $ex) {
                        return false;
                    }

                    if (!is_array($message)) {
                        return false;
                    }

                    if (!array_key_exists('error', $message)) {
                        return false;
                    }

                    if (!array_key_exists('errors', $message['error'])) {
                        return false;
                    }

                    if (!is_array($message['error']['errors'])) {
                        return false;
                    }

                    foreach ($message['error']['errors'] as $error) {
                        if (in_array($error['reason'], self::RETRY_ON_REASON, false)) {
                            return true;
                        }
                        if (str_contains($error['error']['errors'][0]['message'], self::RETRY_MISSING_CREATE_JOB)) {
                            return true;
                        }
                    }

                    return false;
                };
            },
            'retries' => 20,
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
        if ($this->queryTimeout !== 0) {
            $queryOptions['configuration']['jobTimeoutMs'] = $this->queryTimeout * 1000;
        }

        try {
            return $this->client->runQuery($this->client->query($query, $queryOptions)->defaultDataset($this->dataset));
        } catch (ServiceException $e) {
            if (str_contains($e->getMessage(), 'Job timed out after')) {
                throw new UserException('Query exceeded the maximum execution time');
            }
            throw $e;
        }
    }
}
