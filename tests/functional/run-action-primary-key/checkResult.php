<?php

declare(strict_types=1);

use BigQueryTransformation\FunctionalTests\DatadirTest;
use BigQueryTransformation\Tests\Traits\ImportDatasetTrait;
use function PHPUnit\Framework\assertSame;

return static function (DatadirTest $test): void {
    $result = $test->connection->executeQuery('SELECT * FROM `example` ORDER BY id;');
    assertSame(
        [
            ['id' => 1, 'name' => 'test example name', 'usercity' => 'Prague'],
            ['id' => 2, 'name' => 'test example name 2', 'usercity' => 'Brno'],
            ['id' => 3, 'name' => 'test example name 3', 'usercity' => 'Ostrava'],
        ],
        iterator_to_array($result),
    );
};
