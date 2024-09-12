<?php

declare(strict_types=1);

use BigQueryTransformation\FunctionalTests\DatadirTest;
use BigQueryTransformation\Tests\Traits\ImportDatasetTrait;
use function PHPUnit\Framework\assertSame;

return static function (DatadirTest $test): void {
    $result = $test->connection->executeQuery('SELECT * FROM `envvars`;');
    assertSame(
        [
            ['name' => 'KBC_RUNID', 'value' => getenv('KBC_RUNID')],
        ],
        iterator_to_array($result),
    );
};
