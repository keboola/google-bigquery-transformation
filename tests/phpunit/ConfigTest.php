<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests;

use BigQueryTransformation\Config;
use BigQueryTransformation\ConfigDefinition;
use Generator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigTest extends TestCase
{
    public function testConfig(): void
    {
        $configArray = [
            'parameters' => [
                'query_timeout' => 10,
                'blocks' => [
                    [
                        'name' => 'first block',
                        'codes' => [
                            [
                                'name' => 'first code',
                                'script' => [
                                    'DROP TABLE IF EXISTS "output"',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $configDefinition = new ConfigDefinition();

        $config = new Config($configArray, $configDefinition);

        $this->assertEquals($configArray['parameters'], $config->getParameters());
    }

    /**
     * @param array{
     *     parameters: array{
     *      blocks: array<array{
     *          name: string,
     *          codes: array<array{
     *              name: string,
     *              script: string
     *          }>
     *      }>
     *     }
     *    } $config
     * @dataProvider invalidConfigProvider
     */
    public function testInvalidConfig(array $config, string $expectedErrorMsg): void
    {
        $configDefinition = new ConfigDefinition();
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedErrorMsg);
        new Config($config, $configDefinition);
    }

    /**
     * @return \Generator{config: array, expectedErrorMsg: string}
     */
    public function invalidConfigProvider(): Generator
    {
        yield 'missing script' => [
            'config' => [
                'parameters' => [
                    'blocks' => [
                        [
                            'name' => 'first block',
                            'codes' => [
                                [
                                    'name' => 'first code',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'expectedErrorMsg' => 'The child config "script" under "root.parameters.blocks.0.codes.0" must be ' .
                'configured.',
        ];

        yield 'missing code' => [
            'config' => [
                'parameters' => [
                    'blocks' => [
                        [
                            'name' => 'first block',
                        ],
                    ],
                ],
            ],
            'expectedErrorMsg' => 'The child config "codes" under "root.parameters.blocks.0" must be configured.',
        ];

        yield 'missing block' => [
            'config' => [
                'parameters' => [],
            ],
            'expectedErrorMsg' => 'The child config "blocks" under "root.parameters" must be configured.',
        ];
    }
}
