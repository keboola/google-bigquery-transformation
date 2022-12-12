<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use BigQueryTransformation\Exception\MissingTableException;
use Keboola\Component\Manifest\ManifestManager;
use Keboola\Component\Manifest\ManifestManager\Options\OutTableManifestOptions;
use Keboola\Component\UserException;
use Keboola\TableBackendUtils\Column\Bigquery\BigqueryColumn;
use Keboola\TableBackendUtils\Column\ColumnCollection;
use Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableDefinition;
use Psr\Log\LoggerInterface;
use SqlFormatter;
use Throwable;

class Transformation
{

    private BigQueryConnection $connection;
    private LoggerInterface $logger;
    private string $schema;

    /**
     * @throws \BigQueryTransformation\Exception\ApplicationException
     */
    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->connection = new BigQueryConnection($config->getDatabaseConfig());
        $this->logger = $logger;
        $this->schema = $config->getDatabaseConfig()['schema'];
    }

    /**
     * @param array<array{source: string}> $tableNames
     * @throws \Keboola\Component\Manifest\ManifestManager\Options\OptionsValidationException
     * @throws \Google\Cloud\Core\Exception\GoogleException
     * @throws \Keboola\Component\UserException
     */
    public function createManifestMetadata(array $tableNames, ManifestManager $manifestManager): void
    {
        $tableStructures = $this->getTables($tableNames);
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
     * @param array<array{source: string}> $tables
     * @return \Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableDefinition[]
     * @throws \Google\Cloud\Core\Exception\GoogleException
     * @throws \Keboola\Component\UserException
     */

    private function getTables(array $tables): array
    {
        if (count($tables) === 0) {
            return [];
        }

        $sourceTables = array_map(function ($item) {
            return $item['source'];
        }, $tables);

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
                $this->connection->executeQuery($uncommentedQuery);
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
        $result = $this->connection->executeQuery(sprintf('SELECT *
FROM INFORMATION_SCHEMA.COLUMNS
WHERE table_name = "%s"', $tableName));
        /** @var array<int, array{
         *     table_catalog: string,
         *     table_schema: string,
         *     table_name: string,
         *     column_name: string,
         *     ordinal_position: int,
         *     is_nullable: string,
         *     data_type: string,
         *     is_hidden: string,
         *     is_system_defined: string,
         *     is_partitioning_column: string,
         *     clustering_ordinal_position: ?string,
         *     collation_name: string,
         *     column_default: string,
         *     rounding_mode: ?string
         * }> $columnsMeta
         */
        $columnsMeta = iterator_to_array($result->rows());
        if (count($columnsMeta) === 0) {
            throw new MissingTableException($tableName);
        } else {
            $columns = [];

            foreach ($columnsMeta as $col) {
                $columns[] = BigqueryColumn::createFromDB($col);
            }

            return new BigqueryTableDefinition(
                $this->schema,
                $tableName,
                false,
                new ColumnCollection($columns),
                []
            );
        }
    }
}
