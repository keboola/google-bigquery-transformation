<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use Generator;
use PHPUnit\Framework\TestCase;
use SqlFormatter;

class SqlFormatterTest extends TestCase
{
    /**
     * @dataProvider sqlFormatterProvider
     */
    public function testSqlFormatter(string $query, string $expected): void
    {
        self::assertEquals($expected, SqlFormatter::removeComments($query));
    }

    /**
     * @return \Generator<string, array{query: string, expected: string}>
     */
    public function sqlFormatterProvider(): Generator
    {
        yield 'simple query with comments' => [
            'query' => "-- comment\nSELECT 1",
            'expected' => 'SELECT 
  1',
        ];

        yield 'regex function' => [
            'query' => "with tmp as ( select 'www.example.com/?gclid=123xyz' as fullurl) select " .
                "REGEXP_SUBSTR(fullurl, r'gclid=([\w-]+)', 1) as gclid from tmp",
            'expected' =>
                "with tmp as (
  select 
    'www.example.com/?gclid=123xyz' as fullurl
) 
select 
  REGEXP_SUBSTR(fullurl, r'gclid=([\w-]+)', 1) as gclid 
from 
  tmp",
        ];
    }
}
