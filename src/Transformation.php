<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use BigQueryTransformation\Exception\MissingTableException;
use BigQueryTransformation\Exception\TransformationAbortedException;
use Keboola\Component\Manifest\ManifestManager;
use Keboola\Component\Manifest\ManifestManager\Options\OutTable\ManifestOptions;
use Keboola\Component\Manifest\ManifestManager\Options\OutTable\ManifestOptionsSchema;
use Keboola\Component\UserException;
use Keboola\Datatype\Definition\Common;
use Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableDefinition;
use Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableReflection;
use Keboola\TableBackendUtils\TableNotExistsReflectionException;
use Psr\Log\LoggerInterface;
use SqlFormatter;
use Throwable;

class Transformation
{
    public const ABORT_TRANSFORMATION = 'ABORT_TRANSFORMATION';
    private const KBC_DESCRIPTION = 'KBC.description';
    private BigQueryConnection $connection;
    private LoggerInterface $logger;
    private string $schema;
    private SessionVariablesExporter $sessionVariablesExporter;

    public function __construct(
        Config $config,
        LoggerInterface $logger,
        BigQueryConnection $connection,
        SessionVariablesExporter $sessionVariablesExporter,
    ) {
        $this->logger = $logger;
        $this->connection = $connection;
        $this->sessionVariablesExporter = $sessionVariablesExporter;
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
        bool $transformationFailed,
        bool $usingLegacyManifest,
    ): void {
        $tableStructures = $this->getTables($tableNames, $transformationFailed);
        foreach ($tableStructures as $table) {
            $tableDef = $table['definition'];
            $manifestManager->writeTableManifest(
                $tableDef->getTableName(),
                self::createTableManifestOptions(
                    $tableDef,
                    $table['columnDescriptions'],
                    $table['tableDescription'],
                    $usingLegacyManifest,
                ),
                $usingLegacyManifest,
            );
        }
    }

    /**
     * @param array<string, string|null> $columnDescriptions
     * @throws \Keboola\Component\Manifest\ManifestManager\Options\OptionsValidationException
     */
    public static function createTableManifestOptions(
        BigqueryTableDefinition $tableDef,
        array $columnDescriptions,
        ?string $tableDescription,
        bool $usingLegacyManifest,
    ): ManifestOptions {
        $schema = [];

        /** @var \Keboola\TableBackendUtils\Column\Bigquery\BigqueryColumn $column */
        foreach ($tableDef->getColumnsDefinitions() as $column) {
            $dataTypes = [
                'base' => [
                    'type' => $column->getColumnDefinition()->getBasetype(),
                ],
                'bigquery' => [
                    'type' => $column->getColumnDefinition()->getType(),
                ],
            ];

            if ($column->getColumnDefinition()->getLength() !== null) {
                $dataTypes['bigquery']['length'] = $column->getColumnDefinition()->getLength();
            }

            if ($column->getColumnDefinition()->getDefault() !== null) {
                $dataTypes['base']['default'] = $column->getColumnDefinition()->getDefault();
                $dataTypes['bigquery']['default'] = $column->getColumnDefinition()->getDefault();
            }

            $metadata = [];
            foreach ($column->getColumnDefinition()->toMetadata() as $value) {
                $metadata[$value['key']] = $value['value'];
            }

            $schema[] = new ManifestOptionsSchema(
                $column->getColumnName(),
                $dataTypes,
                $column->getColumnDefinition()->isNullable(),
                in_array($column->getColumnName(), $tableDef->getPrimaryKeysNames()),
                self::nonEmptyDescription($columnDescriptions[$column->getColumnName()] ?? null),
                $metadata,
            );
        }

        $tableMetadata = [
            'KBC.name' => $tableDef->getTableName(),
            Common::KBC_METADATA_KEY_BACKEND => 'bigquery',
        ];

        $tableDescription = self::nonEmptyDescription($tableDescription);
        // The legacy serializer drops the top-level description, so legacy manifests carry it as metadata.
        if ($tableDescription !== null && $usingLegacyManifest) {
            $tableMetadata[self::KBC_DESCRIPTION] = $tableDescription;
        }

        $tableManifestOptions = new ManifestOptions();
        $tableManifestOptions
            ->setTableMetadata($tableMetadata)
            ->setSchema($schema)
            ->setManifestType(ManifestOptions::MANIFEST_TYPE_OUTPUT)
        ;

        if ($tableDescription !== null && !$usingLegacyManifest) {
            $tableManifestOptions->setDescription($tableDescription);
        }

        return $tableManifestOptions;
    }

    private static function nonEmptyDescription(?string $description): ?string
    {
        if ($description === null || trim($description) === '') {
            return null;
        }
        return $description;
    }

    /**
     * @param array<array{'source': string, 'write_always'?: bool}> $tables
     * @return array<array{
     *     definition: BigqueryTableDefinition,
     *     tableDescription: string|null,
     *     columnDescriptions: array<string, string|null>
     * }>
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
                $defs[] = [
                    'definition' => $this->getDefinition($tableName),
                    ...$this->getDescriptions($tableName),
                ];
            } catch (MissingTableException $e) {
                $missingTables[] = $e->getTableName();
            }
        }

        if ($missingTables) {
            throw new UserException(
                sprintf(
                    '%s "%s" specified in output were not created by the transformation.',
                    count($missingTables) > 1 ? 'Tables' : 'Table',
                    implode('", "', $missingTables),
                ),
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

            // Capture top-level DECLARE variable names before any further
            // filtering so user-declared session variables can be exported
            // to result.json on successful completion. Placement before the
            // SELECT-skip is intentional and robust to future changes in
            // the read-only-query filter.
            $this->sessionVariablesExporter->captureFromQuery($uncommentedQuery);

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
                    $bqMessage ?? $exception->getMessage(),
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
            $tableName,
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
            $ref->getPrimaryKeysNames(),
        );
    }

    /**
     * Column descriptions cover top-level fields only; nested RECORD fields are not propagated.
     *
     * @return array{tableDescription: string|null, columnDescriptions: array<string, string|null>}
     */
    protected function getDescriptions(string $tableName): array
    {
        /** @var array{description?: string, schema?: array{fields?: array<array{name: string, description?: string}>}} $info */
        $info = $this->connection->getClient()->dataset($this->schema)->table($tableName)->info();

        $columnDescriptions = [];
        foreach ($info['schema']['fields'] ?? [] as $field) {
            $columnDescriptions[$field['name']] = $field['description'] ?? null;
        }

        return [
            'tableDescription' => $info['description'] ?? null,
            'columnDescriptions' => $columnDescriptions,
        ];
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
                sprintf('Transformation aborted with message "%s"', $result[0][self::ABORT_TRANSFORMATION]),
            );
        }
    }
}
