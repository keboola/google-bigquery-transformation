<?php

declare(strict_types=1);

namespace BigQueryTransformation\Tests\Client;

use BigQueryTransformation\Client\Retry;
use Exception;
use Generator;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class RetryTest extends TestCase
{
    private function getException(int $code, string $message = ''): Throwable
    {
        return new Exception($message, $code);
    }

    private function getRequestException(int $code, ?string $message = ''): Throwable
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn(Utils::streamFor($message));
        return new RequestException(
            '',
            $this->createMock(RequestInterface::class),
            $response,
            $this->getException($code),
        );
    }

    /**
     * @return int[][]
     */
    public function retryCodesProvider(): array
    {
        return [
            [200],
            [210],
            [299],
        ];
    }

    /**
     * @dataProvider retryCodesProvider
     */
    public function testSuccessResponse(int $statusCode): void
    {
        $ex = $this->getException($statusCode);
        $this->assertFalse(Retry::shouldRetryException($ex));
    }

    /**
     * @return int[][]
     */
    public function retryCodesErrorProvider(): array
    {
        return [
            [401],
            [429],
            [500],
            [503],
        ];
    }

    /**
     * @dataProvider retryCodesErrorProvider
     */
    public function testRetryOnCodesResponse(int $statusCode): void
    {
        $ex = $this->getException($statusCode);
        $this->assertTrue(Retry::shouldRetryException($ex));
    }

    public function testNotJsonResponse(): void
    {
        $ex = $this->getException(418, 'not json');
        $this->assertFalse(Retry::shouldRetryException($ex));
    }

    public function testNotExpectedContentResponse(): void
    {
        $ex = $this->getException(418, '{"data" : "test"}');
        $this->assertFalse(Retry::shouldRetryException($ex));
    }

    public function responseContentProvider(): Generator
    {
        foreach (['Throwable', 'RequestException'] as $exceptionType) {
            yield 'not error response ' . ' ' . $exceptionType => [
                '{"data" : "test"}',
                false,
                $exceptionType,
            ];

            yield 'errors not array' . ' ' . $exceptionType => [
                '{"error": { "errors" : "test" }}',
                false,
                $exceptionType,
            ];

            yield 'errors empty array' . ' ' . $exceptionType => [
                '{"error": { "errors" : [] }}',
                false,
                $exceptionType,
            ];
            yield 'errors expected errors[0]' . ' ' . $exceptionType => [
                '{"error": { "errors" : [{"test":"test"}] }}',
                false,
                $exceptionType,
            ];

            yield 'errors no reason ' . $exceptionType => [
                '{"error": { "errors" : [{"message":"bigquery.jobs.create"}] }}',
                true,
                $exceptionType,
            ];

            yield 'errors no message ' . $exceptionType => [
                '{"error": { "errors" : [{"reason":"userRateLimitExceeded"}] }}',
                true,
                $exceptionType,
            ];

            yield 'unknown reason and message ' . $exceptionType => [
                '{"error": { "errors" : [{"reason":"unknown","message": "unknown"}] }}',
                false,
                $exceptionType,
            ];

            yield 'Invalid JWT Signature ' . $exceptionType => [
                '{"error":"invalid_grant","error_description":"Invalid JWT Signature."}',
                true,
                $exceptionType,
            ];

            /**
             * @var array{error:array{
             *     code:int,
             *     message:string,
             *     status:string,
             *     errors:array<array{
             *          message:string,
             *          domain:string,
             *          reason:string
             *     }>
             *   }} $json
             */
            $json = json_decode(<<<EOD
{
    "error": {
        "code": 404,
        "message": "Not found: xxx",
        "errors": [
            {
                "message": "Not found: xxx",
                "domain": "global",
                "reason": "notFound"
            }
        ],
        "status": "NOT_FOUND"
    }
}
EOD, true, 512, JSON_THROW_ON_ERROR);

            foreach ([
                         'rateLimitExceeded',
                         'userRateLimitExceeded',
                         'backendError',
                         'jobRateLimitExceeded',
                     ] as $reason) {
                $json['error']['errors'][0]['reason'] = $reason;
                yield 'retry on ' . $reason . ' ' . $exceptionType => [
                    json_encode($json, JSON_THROW_ON_ERROR),
                    true,
                    $exceptionType,
                ];
            }

            $json['error']['errors'][0]['reason'] = 'unknown';
            yield 'not retry on unknown reason ' . $exceptionType => [
                json_encode($json, JSON_THROW_ON_ERROR),
                false,
                $exceptionType,
            ];

            foreach (['bigquery.jobs.create'] as $msg) {
                $json['error']['errors'][0]['reason'] = 'unknown';
                $json['error']['errors'][0]['message'] = $msg;
                yield sprintf('retry on message "%s" "%s"', $msg, $exceptionType) => [
                    json_encode($json, JSON_THROW_ON_ERROR),
                    true,
                    $exceptionType,
                ];
            }
        }
    }

    /**
     * @dataProvider responseContentProvider
     */
    public function testResponseContent(string $json, bool $expectToRetry, string $exceptionType): void
    {
        $ex = $this->getException(418, $json);
        if ($exceptionType === 'RequestException') {
            $this->getRequestException(418, $json);
        }

        $this->assertSame($expectToRetry, Retry::shouldRetryException($ex));
    }
}
