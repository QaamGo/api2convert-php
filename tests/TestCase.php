<?php

declare(strict_types=1);

namespace Api2Convert\Tests;

use Api2Convert\Api2Convert;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Base test case: builds an {@see Api2Convert} client backed by an in-memory
 * PSR-18 mock client, so tests never touch the network and retry/poll waits are
 * instant.
 */
abstract class TestCase extends BaseTestCase
{
    protected MockClient $http;

    protected function setUp(): void
    {
        $this->http = new MockClient();
    }

    /**
     * @param array<string, mixed> $options Client options forwarded to {@see \Api2Convert\Http\Config}.
     */
    protected function client(array $options = []): Api2Convert
    {
        return new Api2Convert(
            apiKey: 'test-key',
            options: $options,
            httpClient: $this->http,
            sleeper: static fn (float $seconds): null => null,
        );
    }

    /**
     * @param array<array-key, mixed> $json
     * @param array<string, string>   $headers
     */
    protected function jsonResponse(int $status, array $json, array $headers = []): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'] + $headers,
            json_encode($json, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The request the mock client received at the given index.
     */
    protected function requestAt(int $index): RequestInterface
    {
        return $this->http->getRequests()[$index];
    }

    /**
     * @return array<string, mixed>
     */
    protected function bodyOf(RequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
