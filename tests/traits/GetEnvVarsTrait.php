<?php

declare(strict_types=1);

namespace BigQueryTransformation\Traits;

use RuntimeException;

trait GetEnvVarsTrait
{
    /**
     * @return array{schema: string, credentials: string}
     */
    public function getEnvVars(): array
    {
        $credentialsJson = getenv('BQ_CREDENTIALS');
        if (!$credentialsJson) {
            throw new RuntimeException('Missing "BQ_CREDENTIALS" environment variable!');
        }

        $dataset = getenv('BQ_DATASET');
        if (!$dataset) {
            throw new RuntimeException('Missing "BQ_DATASET" environment variable!');
        }

        return ['schema' => $dataset, 'credentials' => $credentialsJson];
    }
}
