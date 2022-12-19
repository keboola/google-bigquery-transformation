<?php

declare(strict_types=1);

namespace BigQueryTransformation;

use Keboola\Component\UserException;
use Psr\Log\LoggerInterface;
use SqlFormatter;
use Throwable;

class Transformation
{

    private BigQueryConnection $connection;
    private LoggerInterface $logger;

    /**
     * @throws \BigQueryTransformation\Exception\ApplicationException
     */
    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->connection = new BigQueryConnection($config->getDatabaseConfig());
        $this->logger = $logger;
    }

    /**
     * @param array<array{name: string, codes: array<array{name: string, script: array<int, string>}>}> $blocks
     */
    public function processBlocks(array $blocks): void
    {
        foreach ($blocks as $block) {
            $this->logger->info(sprintf('Processing block "%s".', $block['name']));
            $this->processCodes($block['codes']);
        }
    }

    /**
     * @param array<array{name: string, script: array<int, string>}> $codes
     * @throws \Keboola\Component\UserException
     */
    public function processCodes(array $codes): void
    {
        foreach ($codes as $code) {
            $this->logger->info(sprintf('Processing code "%s".', $code['name']));
            $this->executeQueries($code['name'], $code['script']);
        }
    }

    /**
     * @param array<int, string> $queries
     * @throws \Keboola\Component\UserException
     */
    public function executeQueries(string $blockName, array $queries): void
    {
        foreach ($queries as $query) {
            $uncommentedQuery = SqlFormatter::removeComments($query);

            // Do not execute empty queries
            if (strlen(trim($uncommentedQuery)) === 0) {
                continue;
            }

            if (strtoupper(substr($uncommentedQuery, 0, 6)) === 'SELECT') {
                $this->logger->info(sprintf('Ignoring select query "%s".', $this->queryExcerpt($query)));
                continue;
            }

            $this->logger->info(sprintf('Running query "%s".', $this->queryExcerpt($query)));
            try {
                $this->connection->executeQuery($uncommentedQuery);
            } catch (Throwable $exception) {
                $bqMessage = null;
                $messageArray = json_decode($exception->getMessage(), true);
                if ($messageArray && is_array($messageArray) && isset($messageArray['error']['message'])) {
                    $bqMessage = $messageArray['error']['message'];
                }
                $message = sprintf(
                    'Query "%s" in "%s" failed with error: "%s"',
                    $this->queryExcerpt($query),
                    $blockName,
                    $bqMessage ?? $exception->getMessage()
                );
                throw new UserException($message, 0, $exception);
            }
        }
    }

    private function queryExcerpt(string $query): string
    {
        if (mb_strlen($query) > 1000) {
            return mb_substr($query, 0, 500, 'UTF-8') . "\n...\n" . mb_substr($query, -500, null, 'UTF-8');
        }
        return $query;
    }
}
