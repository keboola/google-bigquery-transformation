<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use BigQueryTransformation\Exception\ApplicationException;
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
    private const ABORT_TRANSFORMATION = 'ABORT_TRANSFORMATION';
    private BigQueryConnection $connection;
    private LoggerInterface $logger;
    private string $schema;

    /** @var array<int, string> */
    private array $declaredVariables = [];

    /**
     * @throws \BigQueryTransformation\Exception\ApplicationException
     */
    public function __construct(Config $config, LoggerInterface $logger)
    {
        $runId = getenv('KBC_RUNID');
        if (!$runId) {
            throw new ApplicationException('Missing KBC_RUNID environment variable');
        }
        $this->logger = $logger;
        $this->connection = new BigQueryConnection(
            $config->getDatabaseConfig(),
            $runId,
            $config->getQueryTimeout(),
            null,
            $this->logger,
            $config->getMaxPollRetries(),
        );
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
        foreach ($tableStructures as $tableDef) {
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
                    null,
                    $metadata,
                );
            }

            $tableMetadata = [
                'KBC.name' => $tableDef->getTableName(),
                Common::KBC_METADATA_KEY_BACKEND => 'bigquery',
            ];

            $tableManifestOptions = new ManifestOptions();
            $tableManifestOptions
                ->setTableMetadata($tableMetadata)
                ->setSchema($schema)
                ->setManifestType(ManifestOptions::MANIFEST_TYPE_OUTPUT)
            ;
            $manifestManager->writeTableManifest(
                $tableDef->getTableName(),
                $tableManifestOptions,
                $usingLegacyManifest,
            );
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
            // to result.json on successful completion. Cheap linear scan;
            // placement before the SELECT-skip is intentional and robust
            // to future changes in the read-only-query filter.
            $this->parseDeclaredVariables($uncommentedQuery);

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

    /**
     * If the query is a top-level DECLARE statement, capture its variable names.
     * A query is treated as a DECLARE only when DECLARE is the first keyword,
     * which matches how user scripts are authored in this component (one
     * statement per script[] entry). DECLARE statements wrapped in
     * BEGIN ... END blocks are local and intentionally skipped.
     */
    private function parseDeclaredVariables(string $query): void
    {
        if (preg_match('/^\s*DECLARE\s+/i', $query, $matches) !== 1) {
            return;
        }
        $this->captureDeclareNames($query, strlen($matches[0]));
    }

    /**
     * Reads comma-separated DECLARE variable names (bare identifiers or
     * backtick-quoted) starting at $offset, until a type/DEFAULT keyword or `;`.
     */
    private function captureDeclareNames(string $query, int $offset): void
    {
        $i = $offset;
        $len = strlen($query);
        $typeStops = ['INT64', 'STRING', 'BOOL', 'BOOLEAN', 'FLOAT64', 'NUMERIC',
            'BIGNUMERIC', 'BYTES', 'DATE', 'DATETIME', 'TIME', 'TIMESTAMP',
            'GEOGRAPHY', 'JSON', 'INTERVAL', 'ARRAY', 'STRUCT', 'DEFAULT'];

        while ($i < $len) {
            // Skip whitespace and commas
            while ($i < $len && (ctype_space($query[$i]) || $query[$i] === ',')) {
                $i++;
            }
            if ($i >= $len || $query[$i] === ';') {
                return;
            }

            // Backtick-quoted name
            if ($query[$i] === '`') {
                $i++;
                $start = $i;
                while ($i < $len && $query[$i] !== '`') {
                    $i++;
                }
                $name = substr($query, $start, $i - $start);
                if ($i < $len) {
                    $i++; // skip closing backtick
                }
                if ($name !== '') {
                    $this->declaredVariables[] = $name;
                }
                continue;
            }

            // Bare identifier
            if (ctype_alpha($query[$i]) || $query[$i] === '_') {
                $start = $i;
                while ($i < $len && (ctype_alnum($query[$i]) || $query[$i] === '_')) {
                    $i++;
                }
                $name = substr($query, $start, $i - $start);

                // Is this a stop keyword?
                if (in_array(strtoupper($name), $typeStops, true)) {
                    return;
                }

                $this->declaredVariables[] = $name;
                continue;
            }

            // Anything else — bail out (we're past the name list).
            return;
        }
    }

    /**
     * Writes user-declared session variables to {dataDir}/out/result.json.
     * No file is written when there are no user-declared variables after
     * filtering out internal `KBC_*` and `ABORT_TRANSFORMATION` names.
     */
    public function exportSessionVariables(string $dataDir): void
    {
        // Defense-in-depth filter + dedup (preserve order)
        $filtered = [];
        foreach ($this->declaredVariables as $name) {
            $upper = strtoupper($name);
            if (str_starts_with($upper, 'KBC_') || $upper === self::ABORT_TRANSFORMATION) {
                continue;
            }
            if (!in_array($name, $filtered, true)) {
                $filtered[] = $name;
            }
        }

        if ($filtered === []) {
            return;
        }

        // Build SELECT with backtick-escaped identifiers
        $selectList = implode(', ', array_map(
            static fn(string $n): string => '`' . str_replace('`', '\\`', $n) . '`',
            $filtered,
        ));
        $result = $this->connection->executeQuery('SELECT ' . $selectList);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = iterator_to_array($result->rows());
        if ($rows === []) {
            return;
        }
        $row = $rows[0];

        $variables = [];
        foreach ($filtered as $name) {
            $value = $row[$name] ?? null;
            $variables[$name] = $this->normaliseVariableValue($value);
        }

        $payload = ['variables' => $variables];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return; // best-effort: never fail the run because of result.json
        }

        file_put_contents($dataDir . '/out/result.json', $json);
    }

    /**
     * Normalise a BigQuery row value to a JSON-encodable scalar/array.
     * Objects (Date, Timestamp, etc.) are cast to string via __toString
     * when available; otherwise serialised through json_encode so types
     * like \JsonSerializable round-trip correctly.
     */
    private function normaliseVariableValue(mixed $value): mixed
    {
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            $encoded = json_encode($value);
            return $encoded === false ? null : $encoded;
        }
        return $value;
    }
}
