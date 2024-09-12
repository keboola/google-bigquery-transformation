<?php

declare(strict_types=1);

namespace BigQueryTransformation\Client;

use Google\Cloud\Core\JsonTrait;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Throwable;

final class Retry
{
    use JsonTrait;

    private const RETRY_MISSING_CREATE_JOB = 'bigquery.jobs.create';
    private const RETRY_ON_REASON = [
        'rateLimitExceeded',
        'userRateLimitExceeded',
        'backendError',
        'jobRateLimitExceeded',
    ];

    public static function shouldRetryException(Throwable $ex): bool
    {
        $statusCode = $ex->getCode();

        if (in_array($statusCode, [429, 500, 503,])) {
            return true;
        }
        if ($statusCode >= 200 && $statusCode < 300) {
            return false;
        }

        $message = $ex->getMessage();
        if ($ex instanceof RequestException && $ex->hasResponse()) {
            $message = (string) $ex->getResponse()?->getBody();
        }

        try {
            $message = self::jsonDecode(
                $message,
                true,
            );
        } catch (InvalidArgumentException $ex) {
            return false;
        }

        if (!is_array($message)) {
            return false;
        }

        if (!array_key_exists('error', $message)) {
            return false;
        }

        if (is_string($message['error']) && str_contains($message['error'], 'invalid_grant')) {
            // {"error":"invalid_grant","error_description":"Invalid JWT Signature."}
            return true;
        }

        if (!array_key_exists('errors', $message['error'])) {
            return false;
        }

        if (!is_array($message['error']['errors'])) {
            return false;
        }

        foreach ($message['error']['errors'] as $error) {
            if (array_key_exists('reason', $error)
                && in_array($error['reason'], self::RETRY_ON_REASON, false)
            ) {
                return true;
            }
            if (array_key_exists('message', $error)
                && str_contains($error['message'], self::RETRY_MISSING_CREATE_JOB)
            ) {
                return true;
            }
        }

        return false;
    }
}
