<?php

declare(strict_types=1);

namespace BigQueryTransformation\FunctionalTests;

use BigQueryTransformation\BigQueryConnection;
use BigQueryTransformation\Traits\GetEnvVarsTrait;
use Keboola\DatadirTests\DatadirTestCase;
use Keboola\DatadirTests\DatadirTestSpecificationInterface;
use RuntimeException;

class DatadirTest extends DatadirTestCase
{
    use GetEnvVarsTrait;

    public BigQueryConnection $connection;

    /**
     * @param array<mixed, mixed> $data
     */
    public function __construct(?string $name = null, array $data = [], int|string $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->connection = new BigQueryConnection($this->getEnvVars());
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
    }
    /**
     * @dataProvider provideDatadirSpecifications
     * @throws \BigQueryTransformation\Exception\ApplicationException
     * @throws \Keboola\DatadirTests\Exception\DatadirTestsException
     */
    public function testDatadir(DatadirTestSpecificationInterface $specification): void
    {
        $tempDatadir = $this->getTempDatadir($specification);

        $process = $this->runScript($tempDatadir->getTmpFolder());

        $this->assertMatchesSpecification($specification, $process, $tempDatadir->getTmpFolder());

        $testProjectDir = $this->getTestFileDir() . '/' . $this->dataName();

        // Load checkResult.php file - used to check if transformation ends as expected
        $checkResultPhpFile = $testProjectDir . '/checkResult.php';
        if (file_exists($checkResultPhpFile)) {
            // Get callback from file and check it
            $initCallback = require $checkResultPhpFile;
            if (!is_callable($initCallback)) {
                throw new RuntimeException(sprintf('File "%s" must return callback!', $checkResultPhpFile));
            }

            $credentialsJson = getenv('BQ_CREDENTIALS');
            if (!$credentialsJson) {
                throw new RuntimeException('Missing "BQ_CREDENTIALS" environment variable!');
            }

            $initCallback($this);
        }
    }

    protected function dropAllTables(): void
    {
        $selectAllTablesQuery = 'select concat("drop table ",table_schema,".",   table_name, ";" )
from INFORMATION_SCHEMA.TABLES';
        $tables = $this->connection->executeQuery($selectAllTablesQuery);
        foreach ($tables as $table) {
            $table = (array) $table;
            $dropTableQuery = reset($table);
            self::assertIsString($dropTableQuery);
            $this->connection->executeQuery($dropTableQuery);
        }
    }
}
