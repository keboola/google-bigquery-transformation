<?php

declare(strict_types=1);

use BigQueryTransformation\FunctionalTests\DatadirTest;
use BigQueryTransformation\Tests\Traits\ImportDatasetTrait;
use function PHPUnit\Framework\assertSame;

return static function (DatadirTest $test): void {
    $result = $test->connection->executeQuery('SELECT * FROM `example` ORDER BY name;');
    assertSame(
        [
            ['name' => 'test example name', 'usercity' => 'Prague', 'population' => 1380000, 'capitalcity' => true],
            ['name' => 'test example name 2', 'usercity' => 'Brno', 'population' => 380000, 'capitalcity' => false],
            ['name' => 'test example name 3', 'usercity' => 'Ostrava', 'population' => 280000, 'capitalcity' => false],
        ],
        iterator_to_array($result)
    );
};
