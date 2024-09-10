<?php

declare(strict_types=1);

use BigQueryTransformation\FunctionalTests\DatadirTest;
use BigQueryTransformation\Tests\Traits\ImportDatasetTrait;
use function PHPUnit\Framework\assertSame;

return static function (DatadirTest $test): void {
    $result = $test->connection->executeQuery('SELECT * FROM `example` ORDER BY name;');
    assertSame(
        [
            ['name' => 'test example name', 'usercity' => 'Prague'],
            ['name' => 'test example name 2', 'usercity' => 'Brno'],
            ['name' => 'test example name 3', 'usercity' => 'Ostrava'],
        ],
        iterator_to_array($result),
    );
};
