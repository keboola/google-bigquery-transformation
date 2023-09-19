<?php

declare(strict_types=1);

namespace BigQueryTransformation\Exception;

use Exception;
use Keboola\CommonExceptions\UserExceptionInterface;

class TransformationAbortedException extends Exception implements UserExceptionInterface
{

}
