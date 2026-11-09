<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use BigQueryTransformation\Transformation;
use Keboola\TableBackendUtils\Column\Bigquery\BigqueryColumn;
use Keboola\TableBackendUtils\Column\ColumnCollection;
use Keboola\TableBackendUtils\Table\Bigquery\BigqueryTableDefinition;
use PHPUnit\Framework\TestCase;

class TableManifestOptionsTest extends TestCase
{
    private const COLUMN_DESCRIPTIONS = [
        'id' => 'Primary identifier',
        'name' => '',
        'city' => null,
        'note' => '   ',
    ];

    public function testNewManifestCarriesDescriptions(): void
    {
        $manifest = Transformation::createTableManifestOptions(
            $this->createTableDefinition(),
            self::COLUMN_DESCRIPTIONS,
            'Customer table',
            false,
        )->toArray(false);

        $this->assertSame('Customer table', $manifest['description']);
        $this->assertSame(
            ['KBC.name' => 'customers', 'KBC.datatype.backend' => 'bigquery'],
            $manifest['table_metadata'],
        );
        $this->assertSame(
            ['id' => 'Primary identifier', 'name' => null, 'city' => null, 'note' => null],
            $this->getSchemaDescriptions($manifest['schema']),
        );
    }

    public function testLegacyManifestCarriesDescriptionsAsMetadata(): void
    {
        $manifest = Transformation::createTableManifestOptions(
            $this->createTableDefinition(),
            self::COLUMN_DESCRIPTIONS,
            'Customer table',
            true,
        )->toArray();

        $this->assertArrayNotHasKey('description', $manifest);
        $this->assertSame(
            [
                ['key' => 'KBC.name', 'value' => 'customers'],
                ['key' => 'KBC.datatype.backend', 'value' => 'bigquery'],
                ['key' => 'KBC.description', 'value' => 'Customer table'],
            ],
            $manifest['metadata'],
        );
        $this->assertContains(
            ['key' => 'KBC.description', 'value' => 'Primary identifier'],
            $manifest['column_metadata']['id'],
        );
        foreach (['name', 'city', 'note'] as $column) {
            $this->assertNotContains('KBC.description', array_column($manifest['column_metadata'][$column], 'key'));
        }
    }

    /**
     * @dataProvider emptyTableDescriptionProvider
     */
    public function testEmptyTableDescriptionIsSkipped(?string $tableDescription, bool $legacy): void
    {
        $manifest = Transformation::createTableManifestOptions(
            $this->createTableDefinition(),
            [],
            $tableDescription,
            $legacy,
        )->toArray($legacy);

        $this->assertArrayNotHasKey('description', $manifest);
        $this->assertStringNotContainsString('KBC.description', (string) json_encode($manifest));
    }

    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public function emptyTableDescriptionProvider(): iterable
    {
        foreach (['legacy' => true, 'new' => false] as $format => $legacy) {
            yield "null, $format manifest" => [null, $legacy];
            yield "empty, $format manifest" => ['', $legacy];
            yield "whitespace, $format manifest" => ['  ', $legacy];
        }
    }

    public function testDataTypesAndPrimaryKeysAreUnchanged(): void
    {
        $manifest = Transformation::createTableManifestOptions(
            $this->createTableDefinition(),
            self::COLUMN_DESCRIPTIONS,
            null,
            false,
        )->toArray(false);

        /** @var array<array{name: string, primary_key: bool, nullable: bool, data_type: array<mixed>}> $schema */
        $schema = $manifest['schema'];
        $this->assertSame(
            ['id' => true, 'name' => false, 'city' => false, 'note' => false],
            array_combine(array_column($schema, 'name'), array_column($schema, 'primary_key')),
        );
        $this->assertSame(
            ['base' => ['type' => 'INTEGER'], 'bigquery' => ['type' => 'INTEGER']],
            $schema[0]['data_type'],
        );
        $this->assertFalse($schema[0]['nullable']);
    }

    private function createTableDefinition(): BigqueryTableDefinition
    {
        return new BigqueryTableDefinition(
            'dataset',
            'customers',
            false,
            new ColumnCollection([
                BigqueryColumn::createFromDB(['name' => 'id', 'type' => 'INTEGER', 'mode' => 'REQUIRED']),
                BigqueryColumn::createFromDB(['name' => 'name', 'type' => 'STRING', 'mode' => 'NULLABLE']),
                BigqueryColumn::createFromDB(['name' => 'city', 'type' => 'STRING', 'mode' => 'NULLABLE']),
                BigqueryColumn::createFromDB(['name' => 'note', 'type' => 'STRING', 'mode' => 'NULLABLE']),
            ]),
            ['id'],
        );
    }

    /**
     * @param array<mixed> $schema
     * @return array<string, string|null>
     */
    private function getSchemaDescriptions(array $schema): array
    {
        $descriptions = [];
        /** @var array{name: string, description?: string} $column */
        foreach ($schema as $column) {
            $descriptions[$column['name']] = $column['description'] ?? null;
        }
        return $descriptions;
    }
}
