<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Exception\ApiException;
use Api2Convert\Exception\AuthenticationException;
use Api2Convert\Exception\NotFoundException;
use Api2Convert\Exception\PaymentRequiredException;
use Api2Convert\Exception\RateLimitException;
use Api2Convert\Exception\ServerException;
use Api2Convert\Exception\ValidationException;
use Api2Convert\Tests\TestCase;

final class ErrorMappingTest extends TestCase
{
    /**
     * @param class-string<ApiException> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statusProvider')]
    public function testStatusMapsToTypedException(int $status, string $expected): void
    {
        $this->http->addResponse($this->jsonResponse($status, ['message' => 'boom'], ['X-Request-Id' => 'req-42']));

        try {
            $this->client(['maxRetries' => 0])->jobs()->get('job-x');
            self::fail('Expected an exception');
        } catch (ApiException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertSame($status, $e->statusCode);
            self::assertSame('boom', $e->getMessage());
            self::assertSame('req-42', $e->requestId);
        }
    }

    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function statusProvider(): iterable
    {
        yield '400' => [400, ValidationException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '402' => [402, PaymentRequiredException::class];
        yield '403' => [403, AuthenticationException::class];
        yield '404' => [404, NotFoundException::class];
        yield '422' => [422, ValidationException::class];
        yield '418' => [418, ApiException::class];
    }

    public function testRateLimitExposesRetryAfterAfterRetriesExhausted(): void
    {
        $this->http->addResponse($this->jsonResponse(429, ['message' => 'slow down'], ['Retry-After' => '7']));

        try {
            $this->client(['maxRetries' => 0])->jobs()->get('job-x');
            self::fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(429, $e->statusCode);
            self::assertSame(7, $e->retryAfter);
        }
    }

    public function testServerErrorMapsToServerException(): void
    {
        $this->http->addResponse($this->jsonResponse(503, ['message' => 'maintenance']));

        $this->expectException(ServerException::class);
        $this->client(['maxRetries' => 0])->jobs()->get('job-x');
    }

    public function testFallsBackToReasonPhraseWhenNoMessage(): void
    {
        $this->http->addResponse(new \GuzzleHttp\Psr7\Response(404, [], ''));

        try {
            $this->client(['maxRetries' => 0])->jobs()->get('job-x');
            self::fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }
}
