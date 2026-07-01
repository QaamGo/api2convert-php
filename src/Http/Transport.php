<?php

declare(strict_types=1);

namespace Api2Convert\Http;

use Api2Convert\Api2Convert;
use Api2Convert\Exception\ApiException;
use Api2Convert\Exception\AuthenticationException;
use Api2Convert\Exception\NetworkException;
use Api2Convert\Exception\NotFoundException;
use Api2Convert\Exception\PaymentRequiredException;
use Api2Convert\Exception\RateLimitException;
use Api2Convert\Exception\ServerException;
use Api2Convert\Exception\ValidationException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The HTTP layer: builds authenticated requests, retries transient failures with
 * exponential backoff, maps error responses to typed exceptions and decodes JSON.
 *
 * Resources talk to the API through {@see request()}; the file uploader and the
 * downloader use {@see send()} / {@see interpret()} / {@see ensureSuccessful()}
 * directly because they need non-JSON bodies and per-job auth.
 *
 * @internal
 */
final class Transport
{
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];
    private const MAX_BACKOFF_SECONDS = 8.0;

    /** @var \Closure(float): void */
    private \Closure $sleeper;

    /**
     * @param \Closure(float): void|null $sleeper Override the sleep used between retries (tests pass a no-op).
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Config $config,
        ?\Closure $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Sleep for the given number of seconds using the configured sleeper.
     * Used by job polling; overridable in tests so waits are instant.
     */
    public function pause(float $seconds): void
    {
        ($this->sleeper)($seconds);
    }

    /**
     * Perform an authenticated JSON request and return the decoded body.
     *
     * @param array<string, mixed>|null $body    Encoded as JSON when not null.
     * @param array<string, string>     $query   Query-string parameters.
     * @param array<string, string>     $headers Extra request headers.
     * @return array<int|string, mixed>
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        array $headers = [],
    ): array {
        $request = $this->requestFactory->createRequest($method, $this->url($path, $query))
            ->withHeader('X-Oc-Api-Key', $this->config->apiKey);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));
        }

        return $this->interpret($this->send($request));
    }

    /**
     * Send a fully-built request with retry/backoff. Adds the common Accept and
     * User-Agent headers but no auth — callers add the header they need.
     */
    public function send(RequestInterface $request): ResponseInterface
    {
        $request = $request
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'api2convert-php/' . Api2Convert::VERSION . ' php/' . PHP_VERSION);

        $attempt = 0;
        while (true) {
            try {
                $response = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                if ($attempt < $this->config->maxRetries) {
                    $this->backoff($attempt);
                    $attempt++;
                    $this->rewind($request);
                    continue;
                }

                throw new NetworkException('Request to API2Convert failed: ' . $e->getMessage(), 0, $e);
            }

            $retryable = in_array($response->getStatusCode(), self::RETRYABLE_STATUSES, true);
            if ($retryable && $attempt < $this->config->maxRetries) {
                $this->backoff($attempt, $response->getHeaderLine('Retry-After'));
                $attempt++;
                $this->rewind($request);
                continue;
            }

            return $response;
        }
    }

    /**
     * Throw a typed exception for error responses; otherwise decode the JSON body.
     *
     * @return array<int|string, mixed>
     */
    public function interpret(ResponseInterface $response): array
    {
        $this->ensureSuccessful($response);

        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Throw the appropriate typed exception when $response is an HTTP error.
     */
    public function ensureSuccessful(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 400) {
            return;
        }

        $body = $this->decodeSafe($response);
        $apiMessage = $body['message'] ?? null;
        $message = is_string($apiMessage) ? $apiMessage : ($response->getReasonPhrase() ?: 'Request failed');
        $requestId = $response->getHeaderLine('X-Request-Id') ?: null;

        throw match (true) {
            $status === 401, $status === 403 => new AuthenticationException($message, $status, $requestId, $body),
            $status === 402 => new PaymentRequiredException($message, $status, $requestId, $body),
            $status === 404 => new NotFoundException($message, $status, $requestId, $body),
            $status === 429 => new RateLimitException(
                $message,
                $status,
                $requestId,
                $body,
                $this->parseRetryAfter($response->getHeaderLine('Retry-After')),
            ),
            $status === 400 || $status === 422 => new ValidationException($message, $status, $requestId, $body),
            $status >= 500 => new ServerException($message, $status, $requestId, $body),
            default => new ApiException($message, $status, $requestId, $body),
        };
    }

    /**
     * Download a file from a (self-contained) URL and return its body stream.
     * Used for output downloads — these URLs need no API key.
     *
     * @param array<string, string> $headers
     */
    public function download(string $uri, array $headers = []): \Psr\Http\Message\StreamInterface
    {
        $request = $this->requestFactory->createRequest('GET', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->send($request);
        $this->ensureSuccessful($response);

        return $response->getBody();
    }

    /**
     * @param array<string, string> $query
     */
    public function url(string $path, array $query = []): string
    {
        $url = $this->config->baseUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSafe(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function backoff(int $attempt, string $retryAfter = ''): void
    {
        $retry = $this->parseRetryAfter($retryAfter);
        $seconds = $retry !== null ? (float) $retry : min(self::MAX_BACKOFF_SECONDS, 0.5 * (2 ** $attempt));
        ($this->sleeper)($seconds);
    }

    private function parseRetryAfter(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function rewind(RequestInterface $request): void
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
    }
}
