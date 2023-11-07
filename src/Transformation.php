<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use BigQueryTransformation\Exception\ApplicationException;
use BigQueryTransformation\Exception\MissingTableException;
use BigQueryTransformation\Exception\TransformationAbortedException;
use Keboola\Component\Manifest\ManifestManager;
use Keboola\Component\Manifest\ManifestManager\Options\OutTableManifestOptions;
use Keboola\Component\UserException;
use Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableDefinition;
use Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableReflection;
use Keboola\TableBackendUtils\TableNotExistsReflectionException;
use Psr\Log\LoggerInterface;
use SqlFormatter;
use Throwable;

class Transformation
{
    private const ABORT_TRANSFORMATION = 'ABORT_TRANSFORMATION';
    private BigQueryConnection $connection;
    private LoggerInterface $logger;
    private string $schema;

    /**
     * @throws \BigQueryTransformation\Exception\ApplicationException
     */
    public function __construct(Config $config, LoggerInterface $logger)
    {
        $runId = getenv('KBC_RUNID');
        if (!$runId) {
            throw new ApplicationException('Missing KBC_RUNID environment variable');
        }
        $this->connection = new BigQueryConnection(
            $config->getDatabaseConfig(),
            $runId,
            $config->getQueryTimeout()
        );
        $this->logger = $logger;
        /** @var string $schema */
        $schema = $config->getDatabaseConfig()['schema'];
        $this->schema = $schema;
    }

    /**
     * @param array<array{'source': string, 'write_always'?: bool}> $tableNames
     * @throws \Keboola\Component\Manifest\ManifestManager\Options\OptionsValidationException
     * @throws \Google\Cloud\Core\Exception\GoogleException
     * @throws \Keboola\Component\UserException
     */
    public function createManifestMetadata(
        array $tableNames,
        ManifestManager $manifestManager,
        bool $transformationFailed
    ): void {
        $tableStructures = $this->getTables($tableNames, $transformationFailed);
        foreach ($tableStructures as $tableDef) {
            $columnsMetadata = (object) [];
            /** @var \Keboola\TableBackendUtils\Column\Bigquery\BigqueryColumn $column */
            foreach ($tableDef->getColumnsDefinitions() as $column) {
                $columnsMetadata->{$column->getColumnName()} = $column->getColumnDefinition()->toMetadata();
            }
            $tableMetadata = [];
            $tableMetadata[] = [
                'key' => 'KBC.name',
                'value' => $tableDef->getTableName(),
            ];

            $tableManifestOptions = new OutTableManifestOptions();
            $tableManifestOptions
                ->setMetadata($tableMetadata)
                ->setColumns($tableDef->getColumnsNames())
                ->setColumnMetadata($columnsMetadata)
            ;
            $manifestManager->writeTableManifest($tableDef->getTableName(), $tableManifestOptions);
        }
    }

    /**
     * @param array<array{'source': string, 'write_always'?: bool}> $tables
     * @return \Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableDefinition[]
     * @throws \Google\Cloud\Core\Exception\GoogleException
     * @throws \Keboola\Component\UserException
     */

    private function getTables(array $tables, bool $transformationFailed): array
    {
        if (count($tables) === 0) {
            return [];
        }

        if ($transformationFailed) {
            $tables = array_filter($tables, function ($item) {
                return isset($item['write_always']) && $item['write_always'] === true;
            });
        }

        $sourceTables = array_column($tables, 'source');

        $defs = [];
        $missingTables = [];
        foreach ($sourceTables as $tableName) {
            try {
                $defs[] = $this->getDefinition($tableName);
            } catch (MissingTableException $e) {
                $missingTables[] = $e->getTableName();
            }
        }

        if ($missingTables) {
            throw new UserException(
                sprintf(
                    '%s "%s" specified in output were not created by the transformation.',
                    count($missingTables) > 1 ? 'Tables' : 'Table',
                    implode('", "', $missingTables)
                )
            );
        }

        return $defs;
    }

    /**
     * @param array<array{name: string, codes: array<array{name: string, script: array<int, string>}>}> $blocks
     * @throws \Keboola\Component\UserException
     */
    public function processBlocks(array $blocks): void
    {
        foreach ($blocks as $block) {
            $this->logger->info(sprintf('Processing block "%s".', $block['name']));
            $this->processCodes($block['codes']);
        }
    }

    /**
     * @param array<array{name: string, script: array<int, string>}> $codes
     * @throws \Keboola\Component\UserException
     */
    public function processCodes(array $codes): void
    {
        foreach ($codes as $code) {
            $this->logger->info(sprintf('Processing code "%s".', $code['name']));
            $this->executeQueries($code['name'], $code['script']);
        }
    }

    /**
     * @param array<int, string> $queries
     * @throws \Keboola\Component\UserException
     */
    public function executeQueries(string $blockName, array $queries): void
    {
        foreach ($queries as $query) {
            $uncommentedQuery = SqlFormatter::removeComments($query);

            // Do not execute empty queries
            if (strlen(trim($uncommentedQuery)) === 0) {
                continue;
            }

            if (strtoupper(substr($uncommentedQuery, 0, 6)) === 'SELECT') {
                $this->logger->info(sprintf('Ignoring select query "%s".', $this->queryExcerpt($query)));
                continue;
            }

            $this->logger->info(sprintf('Running query "%s".', $this->queryExcerpt($query)));
            try {
                $result = $this->connection->executeQuery($uncommentedQuery);
                $id = $result->identity();
                $resultUrlLog = 'Query results URL: ' .
                    'https://console.cloud.google.com/bigquery?project=%s&j=bq:%s:%s&page=queryresults';
                $this->logger->info(sprintf($resultUrlLog, $id['projectId'], $id['location'], $id['jobId']));
            } catch (Throwable $exception) {
                $bqMessage = null;
                $messageArray = json_decode($exception->getMessage(), true);
                if ($messageArray && is_array($messageArray) && isset($messageArray['error']['message'])) {
                    $bqMessage = $messageArray['error']['message'];
                }
                $message = sprintf(
                    'Query "%s" in "%s" failed with error: "%s"',
                    $this->queryExcerpt($query),
                    $blockName,
                    $bqMessage ?? $exception->getMessage()
                );
                throw new UserException($message, 0, $exception);
            }

            $pattern = sprintf('/%s/i', preg_quote(self::ABORT_TRANSFORMATION, '/'));
            if (preg_match($pattern, $uncommentedQuery)) {
                $this->checkUserTermination();
            }
        }
    }

    private function queryExcerpt(string $query): string
    {
        if (mb_strlen($query) > 1000) {
            return mb_substr($query, 0, 500, 'UTF-8') . "\n...\n" . mb_substr($query, -500, null, 'UTF-8');
        }
        return $query;
    }

    /**
     * @throws \Google\Cloud\Core\Exception\GoogleException
     * @throws \BigQueryTransformation\Exception\MissingTableException
     */
    protected function getDefinition(string $tableName): BigqueryTableDefinition
    {
        $ref = new BigqueryTableReflection(
            $this->connection->getClient(),
            $this->schema,
            $tableName
        );
        try {
            $columns = $ref->getColumnsDefinitions();
        } catch (TableNotExistsReflectionException $e) {
            throw new MissingTableException($tableName);
        }
        return new BigqueryTableDefinition(
            $this->schema,
            $tableName,
            false,
            $columns,
            []
        );
    }

    public function declareAbortVariable(): void
    {
        $this->connection->executeQuery(
            sprintf('DECLARE %s STRING DEFAULT \'\'', self::ABORT_TRANSFORMATION),
        );
    }

    public function declareEnvVars(): void
    {
        $kbcEnvVars = [
            'KBC_RUNID',
            'KBC_PROJECTID',
            'KBC_STACKID',
            'KBC_CONFIGID',
            'KBC_COMPONENTID',
            'KBC_CONFIGROWID',
            'KBC_BRANCHID',
        ];

        $queries = [];
        foreach ($kbcEnvVars as $kbcEnvVar) {
            $value = getenv($kbcEnvVar);
            if ($value) {
                $queries[] = sprintf('DECLARE %s STRING DEFAULT \'%s\';', $kbcEnvVar, $value);
            }
        }

        $this->connection->executeQuery(
            implode("\n", $queries),
        );
    }

    /**
     * @throws \Google\Cloud\Core\Exception\GoogleException
     * @throws \BigQueryTransformation\Exception\TransformationAbortedException
     * @throws \Keboola\Component\UserException
     */
    protected function checkUserTermination(): void
    {
        $this->logger->info('Checking user termination');
        $result = $this->connection->executeQuery(sprintf('SELECT %s', self::ABORT_TRANSFORMATION));
        /** @var array<0, array<'ABORT_TRANSFORMATION', string>> $result */
        $result = iterator_to_array($result->rows());
        if ($result[0][self::ABORT_TRANSFORMATION] !== '') {
            throw new TransformationAbortedException(
                sprintf('Transformation aborted with message "%s"', $result[0][self::ABORT_TRANSFORMATION])
            );
        }
    }
}
