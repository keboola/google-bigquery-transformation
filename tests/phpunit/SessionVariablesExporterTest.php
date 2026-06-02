<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use BigQueryTransformation\BigQueryConnection;
use BigQueryTransformation\SessionVariablesExporter;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Google\Cloud\BigQuery\QueryResults;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SessionVariablesExporterTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir() . '/sv-exporter-' . uniqid('', true);
        mkdir($this->dataDir . '/out', 0o777, true);
    }

    protected function tearDown(): void
    {
        $file = $this->dataDir . '/out/result.json';
        if (is_file($file)) {
            unlink($file);
        }
        @rmdir($this->dataDir . '/out');
        @rmdir($this->dataDir);
        parent::tearDown();
    }

    public function testCapturesSingleStringDeclare(): void
    {
        $exporter = $this->makeExporter(['my_string' => 'hello']);
        $exporter->captureFromQuery("DECLARE my_string STRING DEFAULT 'hello'");
        $exporter->export($this->dataDir);

        self::assertSame(['variables' => ['my_string' => 'hello']], $this->readResult());
    }

    public function testCapturesCommaSeparatedDeclare(): void
    {
        $exporter = $this->makeExporter(['a' => 'x', 'b' => 'x', 'c' => 'x']);
        $exporter->captureFromQuery("DECLARE a, b, c STRING DEFAULT 'x'");
        $exporter->export($this->dataDir);

        self::assertSame(['a', 'b', 'c'], array_keys($this->readVariables()));
    }

    public function testCapturesBacktickQuotedName(): void
    {
        $exporter = $this->makeExporter(['weird name' => 'special']);
        $exporter->captureFromQuery("DECLARE `weird name` STRING DEFAULT 'special'");
        $exporter->export($this->dataDir);

        self::assertSame(['variables' => ['weird name' => 'special']], $this->readResult());
    }

    public function testSelectQueryDoesNotCapture(): void
    {
        $exporter = $this->makeExporter();
        $exporter->captureFromQuery('SELECT 1');
        $exporter->export($this->dataDir);

        self::assertNull($this->readResult());
    }

    public function testNestedBeginDeclareIsSkipped(): void
    {
        // The whole BEGIN...END block lands here as a single query; the
        // first keyword is BEGIN, so the parser must not capture `inner`.
        $exporter = $this->makeExporter();
        $exporter->captureFromQuery("BEGIN DECLARE inner STRING DEFAULT 'x'; END");
        $exporter->export($this->dataDir);

        self::assertNull($this->readResult());
    }

    public function testKbcPrefixedNamesAreFiltered(): void
    {
        $exporter = $this->makeExporter(['user_var' => 'visible']);
        $exporter->captureFromQuery("DECLARE KBC_internal STRING DEFAULT ''");
        $exporter->captureFromQuery("DECLARE user_var STRING DEFAULT 'visible'");
        $exporter->export($this->dataDir);

        self::assertSame(['variables' => ['user_var' => 'visible']], $this->readResult());
    }

    public function testAbortTransformationNameIsFiltered(): void
    {
        $exporter = $this->makeExporter(['user_var' => 'kept']);
        $exporter->captureFromQuery("DECLARE ABORT_TRANSFORMATION STRING DEFAULT ''");
        $exporter->captureFromQuery("DECLARE user_var STRING DEFAULT 'kept'");
        $exporter->export($this->dataDir);

        self::assertSame(['variables' => ['user_var' => 'kept']], $this->readResult());
    }

    public function testDuplicateNamesAreDeduped(): void
    {
        $exporter = $this->makeExporter(['x' => 'v']);
        $exporter->captureFromQuery("DECLARE x STRING DEFAULT ''");
        $exporter->captureFromQuery("DECLARE x STRING DEFAULT ''");
        $exporter->export($this->dataDir);

        self::assertSame(['x'], array_keys($this->readVariables()));
    }

    public function testNoUserVariablesProducesNoFile(): void
    {
        $exporter = $this->makeExporter();
        $exporter->captureFromQuery("DECLARE KBC_internal STRING DEFAULT ''");
        $exporter->export($this->dataDir);

        self::assertNull($this->readResult());
    }

    public function testEmptyResultRowsProducesNoFile(): void
    {
        // Generator yields no rows; export must short-circuit.
        $exporter = $this->makeExporter(null);
        $exporter->captureFromQuery("DECLARE x STRING DEFAULT ''");
        $exporter->export($this->dataDir);

        self::assertNull($this->readResult());
    }

    public function testScalarValuesPassThrough(): void
    {
        $exporter = $this->makeExporter([
            'a_string' => 'hi',
            'an_int' => 42,
            'a_bool' => true,
            'a_null' => null,
        ]);
        $exporter->captureFromQuery("DECLARE a_string, an_int, a_bool, a_null STRING DEFAULT ''");
        $exporter->export($this->dataDir);

        self::assertSame(
            ['variables' => ['a_string' => 'hi', 'an_int' => 42, 'a_bool' => true, 'a_null' => null]],
            $this->readResult(),
        );
    }

    public function testDateTimeNormalisedToIso8601WithMicroseconds(): void
    {
        $dt = new DateTimeImmutable('2026-01-15 12:34:56.123456', new DateTimeZone('UTC'));
        $exporter = $this->makeExporter(['ts' => $dt]);
        $exporter->captureFromQuery('DECLARE ts DATETIME DEFAULT CURRENT_DATETIME()');
        $exporter->export($this->dataDir);

        self::assertSame(
            ['variables' => ['ts' => '2026-01-15T12:34:56.123456+00:00']],
            $this->readResult(),
        );
    }

    public function testStringableObjectIsCastViaToString(): void
    {
        $stringable = new class {
            public function __toString(): string
            {
                return '42.000000';
            }
        };
        $exporter = $this->makeExporter(['n' => $stringable]);
        $exporter->captureFromQuery('DECLARE n NUMERIC DEFAULT 0');
        $exporter->export($this->dataDir);

        self::assertSame(['variables' => ['n' => '42.000000']], $this->readResult());
    }

    public function testArrayValueIsJsonStringified(): void
    {
        $exporter = $this->makeExporter(['nums' => [1, 2, 3]]);
        $exporter->captureFromQuery('DECLARE nums ARRAY<INT64> DEFAULT [1,2,3]');
        $exporter->export($this->dataDir);

        self::assertSame(['variables' => ['nums' => '[1,2,3]']], $this->readResult());
    }

    public function testStructValueIsJsonStringifiedPreservingKeys(): void
    {
        $exporter = $this->makeExporter(['info' => ['name' => 'Alice', 'age' => 30]]);
        $exporter->captureFromQuery("DECLARE info STRUCT<name STRING, age INT64> DEFAULT STRUCT('Alice', 30)");
        $exporter->export($this->dataDir);

        self::assertSame(
            ['variables' => ['info' => '{"name":"Alice","age":30}']],
            $this->readResult(),
        );
    }

    public function testNestedStringableInsideArrayIsCast(): void
    {
        $date = new class {
            public function __toString(): string
            {
                return '2026-01-15';
            }
        };
        $exporter = $this->makeExporter(['dates' => [$date, $date]]);
        $exporter->captureFromQuery("DECLARE dates ARRAY<DATE> DEFAULT [DATE '2026-01-15']");
        $exporter->export($this->dataDir);

        self::assertSame(
            ['variables' => ['dates' => '["2026-01-15","2026-01-15"]']],
            $this->readResult(),
        );
    }

    public function testJsonSerializableObjectIsExpandedRecursively(): void
    {
        $obj = new class implements JsonSerializable {
            /** @return array<string, string> */
            public function jsonSerialize(): array
            {
                return ['k' => 'v'];
            }
        };
        $exporter = $this->makeExporter(['payload' => $obj]);
        $exporter->captureFromQuery("DECLARE payload JSON DEFAULT JSON '{}'");
        $exporter->export($this->dataDir);

        // jsonSerialize -> array -> top-level array-to-JSON-string conversion
        self::assertSame(['variables' => ['payload' => '{"k":"v"}']], $this->readResult());
    }

    public function testJsonEncodeFailureLogsWarningAndSkipsFile(): void
    {
        // Invalid UTF-8 passes the scalar normalisation but breaks the final
        // payload encoding; the exporter must log a warning and write nothing.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $exporter = $this->makeExporter(['v' => "\xB1\x31"], $logger);
        $exporter->captureFromQuery("DECLARE v STRING DEFAULT ''");
        $exporter->export($this->dataDir);

        self::assertNull($this->readResult());
    }

    public function testVariableNamedLikeTypeKeywordIsDropped(): void
    {
        // Known limitation: the parser stops at the first type keyword, so a
        // variable named `date` (a legal, non-reserved identifier in BigQuery)
        // matches the DATE stop and is never captured. Pinned intentionally.
        $exporter = $this->makeExporter(['date' => '2026-01-01']);
        $exporter->captureFromQuery("DECLARE date STRING DEFAULT '2026-01-01'");
        $exporter->export($this->dataDir);

        self::assertNull($this->readResult());
    }

    public function testTypeKeywordNameMidListTruncatesRemainingNames(): void
    {
        // Same limitation in a comma-separated list: parsing stops at `date`,
        // so both `date` and the trailing `c` are dropped; only `a` survives.
        $exporter = $this->makeExporter(['a' => 'x', 'c' => 'x']);
        $exporter->captureFromQuery('DECLARE a, date, c STRING DEFAULT \'x\'');
        $exporter->export($this->dataDir);

        self::assertSame(['variables' => ['a' => 'x']], $this->readResult());
    }

    public function testSelectQueryIsBuiltFromCapturedNames(): void
    {
        $queryResults = $this->createMock(QueryResults::class);
        $queryResults->method('rows')->willReturn($this->rowGenerator(['a' => 1, 'b' => 2]));

        $connection = $this->createMock(BigQueryConnection::class);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with('SELECT `a`, `b`')
            ->willReturn($queryResults);

        $exporter = new SessionVariablesExporter($connection, new NullLogger());
        $exporter->captureFromQuery('DECLARE a, b INT64 DEFAULT 0');
        $exporter->export($this->dataDir);
    }

    /**
     * @param array<string, mixed>|null $row null = empty rows; [] also accepted as empty
     */
    private function makeExporter(?array $row = [], ?LoggerInterface $logger = null): SessionVariablesExporter
    {
        $queryResults = $this->createMock(QueryResults::class);
        $queryResults->method('rows')->willReturn(
            $row === null || $row === [] ? $this->emptyGenerator() : $this->rowGenerator($row),
        );

        $connection = $this->createMock(BigQueryConnection::class);
        $connection->method('executeQuery')->willReturn($queryResults);

        return new SessionVariablesExporter($connection, $logger ?? new NullLogger());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowGenerator(array $row): Generator
    {
        yield $row;
    }

    private function emptyGenerator(): Generator
    {
        yield from [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readResult(): ?array
    {
        $file = $this->dataDir . '/out/result.json';
        if (!is_file($file)) {
            return null;
        }
        /** @var string $content */
        $content = file_get_contents($file);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true);
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function readVariables(): array
    {
        $result = $this->readResult();
        self::assertNotNull($result, 'expected out/result.json to exist');
        self::assertArrayHasKey('variables', $result);
        self::assertIsArray($result['variables']);
        return $result['variables'];
    }
}
