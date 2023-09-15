<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use Keboola\Component\BaseComponent;
use Keboola\Component\Manifest\ManifestManager;

class Component extends BaseComponent
{
    /**
     * @throws \BigQueryTransformation\Exception\ApplicationException
     * @throws \Keboola\Component\Manifest\ManifestManager\Options\OptionsValidationException
     * @throws \Keboola\Component\UserException
     * @throws \Google\Cloud\Core\Exception\GoogleException
     */
    protected function run(): void
    {
        /** @var \BigQueryTransformation\Config $config */
        $config = $this->getConfig();

        $transformation = new Transformation($config, $this->getLogger());
        $transformation->declareAbortVariable();
        $transformation->processBlocks($config->getBlocks());

        /** @var array<array{source: string}> $tables */
        $tables = $config->getExpectedOutputTables();
        $transformation->createManifestMetadata(
            $tables,
            new ManifestManager($this->getDataDir())
        );
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }
}
