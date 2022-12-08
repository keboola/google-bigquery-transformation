<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use BigQueryTransformation\Exception\ApplicationException;
use InvalidArgumentException;
use Keboola\Component\Config\BaseConfig;

class Config extends BaseConfig
{
    /**
     * @return array<array{name: string, codes: array<array{name: string, script: array<int, string>}>}>
     */
    public function getBlocks(): array
    {
        return $this->getArrayValue(['parameters', 'blocks']);
    }

    /**
     * @return array<string, string>
     * @throws \BigQueryTransformation\Exception\ApplicationException
     */
    public function getDatabaseConfig(): array
    {
        try {
            return $this->getArrayValue(['authorization', 'workspace']);
        } catch (InvalidArgumentException) {
            throw new ApplicationException('Missing authorization for workspace');
        }
    }
}
