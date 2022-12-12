<?php

declare(strict_types=1);

namespace BigQueryTransformation\Exception;

use Exception;
use Keboola\CommonExceptions\ApplicationExceptionInterface;

class MissingTableException extends Exception implements ApplicationExceptionInterface
{
    private string $tableName;

    public function __construct(string $tableName)
    {
        parent::__construct();
        $this->tableName = $tableName;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }
}
