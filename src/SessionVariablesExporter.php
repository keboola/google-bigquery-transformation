<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use DateTimeInterface;
use JsonSerializable;
use Psr\Log\LoggerInterface;

class SessionVariablesExporter
{
    /** @var array<int, string> */
    private array $declaredVariables = [];

    public function __construct(
        private readonly BigQueryConnection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * If $query is a top-level DECLARE statement, capture its variable names.
     * A query is treated as a DECLARE only when DECLARE is the first keyword,
     * which matches how user scripts are authored in this component (one
     * statement per script[] entry). DECLAREs wrapped in BEGIN ... END blocks
     * are local and intentionally skipped.
     */
    public function captureFromQuery(string $query): void
    {
        if (preg_match('/^\s*DECLARE\s+/i', $query, $matches) !== 1) {
            return;
        }
        $this->captureDeclareNames($query, strlen($matches[0]));
    }

    /**
     * Writes user-declared session variables to {dataDir}/out/result.json.
     * No file is written when there are no user-declared variables after
     * filtering out internal `KBC_*` and `ABORT_TRANSFORMATION` names.
     */
    public function export(string $dataDir): void
    {
        $filtered = $this->filterUserVariables();
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
            $normalised = $this->normaliseValue($value);
            // Keboola's component runner stores only scalar/null variable
            // values; non-scalar results (ARRAY/STRUCT) would be silently
            // dropped. Encode them as a JSON string so the structure
            // reaches downstream tasks intact.
            if (is_array($normalised)) {
                $encoded = json_encode($normalised, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $normalised = $encoded === false ? null : $encoded;
            }
            $variables[$name] = $normalised;
        }

        $payload = ['variables' => $variables];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->logger->warning(sprintf(
                'Failed to JSON-encode session variables for result.json: %s',
                json_last_error_msg(),
            ));
            return;
        }

        if (file_put_contents($dataDir . '/out/result.json', $json) === false) {
            $this->logger->warning(sprintf(
                'Failed to write session variables to result.json at "%s".',
                $dataDir . '/out/result.json',
            ));
        }
    }

    /**
     * @return array<int, string>
     */
    private function filterUserVariables(): array
    {
        $filtered = [];
        foreach ($this->declaredVariables as $name) {
            $upper = strtoupper($name);
            if (str_starts_with($upper, 'KBC_') || $upper === Transformation::ABORT_TRANSFORMATION) {
                continue;
            }
            if (!in_array($name, $filtered, true)) {
                $filtered[] = $name;
            }
        }
        return $filtered;
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
     * Normalise a BigQuery row value to a JSON-encodable scalar/array.
     * \DateTimeInterface gets explicit ISO-8601 because base \DateTime has
     * no __toString; arrays are recursed so STRUCT/ARRAY values containing
     * BQ objects (Date, Numeric, ...) are normalised element-by-element.
     */
    private function normaliseValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normaliseValue($v);
            }
            return $out;
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            if ($value instanceof JsonSerializable) {
                return $this->normaliseValue($value->jsonSerialize());
            }
            if (is_iterable($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = $this->normaliseValue($v);
                }
                return $out;
            }
        }

        return null;
    }
}
