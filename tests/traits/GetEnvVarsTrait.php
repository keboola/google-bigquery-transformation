<?php

declare(strict_types=1);

namespace BigQueryTransformation\Traits;

use RuntimeException;

trait GetEnvVarsTrait
{
    /**
     * @return array{schema: string, credentials: array<string, string>}
     */
    public function getEnvVars(): array
    {
        $credentialsEnvVars = [
            'BQ_CREDENTIALS_TYPE',
            'BQ_CREDENTIALS_PROJECT_ID',
            'BQ_CREDENTIALS_PRIVATE_KEY_ID',
            'BQ_CREDENTIALS_PRIVATE_KEY',
            'BQ_CREDENTIALS_CLIENT_EMAIL',
            'BQ_CREDENTIALS_CLIENT_ID',
            'BQ_CREDENTIALS_AUTH_URI',
            'BQ_CREDENTIALS_TOKEN_URI',
            'BQ_CREDENTIALS_AUTH_PROVIDER_X509_CERT_URL',
            'BQ_CREDENTIALS_CLIENT_X509_CERT_URL',
        ];

        $credentials = [];
        foreach ($credentialsEnvVars as $credentialsEnvVar) {
            $envVar = getenv($credentialsEnvVar);
            if (!$envVar) {
                throw new RuntimeException(sprintf('Missing "%s" environment variable!', $credentialsEnvVar));
            }
            $credentials[strtolower(substr($credentialsEnvVar, 15))] = $envVar;
        }

        $dataset = getenv('BQ_DATASET');
        if (!$dataset) {
            throw new RuntimeException('Missing "BQ_DATASET" environment variable!');
        }

        return ['schema' => $dataset, 'credentials' => $credentials];
    }

    public function getRunIdEnvVar(): string
    {
        $runId = getenv('KBC_RUNID');
        $this->assertNotFalse($runId, 'KBC_RUNID env var is not set');
        return $runId;
    }
}
