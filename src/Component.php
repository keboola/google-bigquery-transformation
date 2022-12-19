<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use Keboola\Component\BaseComponent;

class Component extends BaseComponent
{
    /**
     * @throws \BigQueryTransformation\Exception\ApplicationException
     */
    protected function run(): void
    {
        /** @var \BigQueryTransformation\Config $config */
        $config = $this->getConfig();
        $transformation = new Transformation($config, $this->getLogger());
        $transformation->processBlocks($config->getBlocks());
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
