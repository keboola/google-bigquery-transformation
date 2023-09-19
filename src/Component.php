<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use BigQueryTransformation\Exception\TransformationAbortedException;
use Keboola\Component\BaseComponent;
use Keboola\Component\Manifest\ManifestManager;

class Component extends BaseComponent
{
    /**
     * @throws \BigQueryTransformation\Exception\ApplicationException
     * @throws \Keboola\Component\Manifest\ManifestManager\Options\OptionsValidationException
     * @throws \Keboola\Component\UserException
     * @throws \Google\Cloud\Core\Exception\GoogleException
     * @throws \BigQueryTransformation\Exception\TransformationAbortedException
     */
    protected function run(): void
    {
        /** @var \BigQueryTransformation\Config $config */
        $config = $this->getConfig();

        $transformation = new Transformation($config, $this->getLogger());
        $transformation->declareAbortVariable();
        $transformation->declareEnvVars();
        try {
            $transformation->processBlocks($config->getBlocks());
        } catch (TransformationAbortedException $e) {
            $this->generateManifest($config, $transformation, true);
            throw $e;
        }

        $this->generateManifest($config, $transformation);
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }

    /**
     * @throws \Google\Cloud\Core\Exception\GoogleException
     * @throws \Keboola\Component\Manifest\ManifestManager\Options\OptionsValidationException
     * @throws \Keboola\Component\UserException
     */
    protected function generateManifest(
        Config $config,
        Transformation $transformation,
        bool $transformationFailed = false
    ): void {
        /** @var array<array{'source': string, 'write_always'?: bool}> $tables */
        $tables = $config->getExpectedOutputTables();
        $transformation->createManifestMetadata(
            $tables,
            new ManifestManager($this->getDataDir()),
            $transformationFailed
        );
    }
}
