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

    /**
     * Upper bound for an honored `Retry-After`. A server (or misconfigured proxy)
     * asking for an absurd delay can't stall a worker for hours — we never sleep
     * longer than this inside a single retry.
     */
    private const MAX_RETRY_AFTER_SECONDS = 120.0;

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
     * Sleep for (at least) the given number of seconds using the configured sleeper.
     * Used by job polling; overridable in tests so waits are instant. A small upward
     * jitter is added so a fleet that starts waiting at the same instant does not poll
     * in lockstep (thundering herd).
     */
    public function pause(float $seconds): void
    {
        ($this->sleeper)($this->jitter($seconds));
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

        // A request may only be retried if its body can be replayed from the start.
        // A non-seekable body (e.g. a socket/pipe wrapped in a multipart upload) would
        // re-send from an exhausted position, producing a truncated/corrupt request —
        // so we send such a request exactly once.
        $replayable = $this->isReplayable($request);

        // A non-idempotent request (POST /jobs, /jobs/{id}/input, /presets, uploads)
        // must not be auto-retried on a 5xx or network error: the backend may have
        // already acted on the first attempt, so a blind retry would create a
        // duplicate job — and a duplicate charge. Such requests are retried only when
        // they carry an Idempotency-Key. A 429 is safe to retry for any method, since
        // it is rejected before the request is processed.
        $idempotent = $this->isIdempotent($request);

        $attempt = 0;
        while (true) {
            try {
                $response = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                if ($replayable && $idempotent && $attempt < $this->config->maxRetries) {
                    $this->backoff($attempt);
                    $attempt++;
                    $this->rewind($request);
                    continue;
                }

                throw new NetworkException('Request to API2Convert failed: ' . $e->getMessage(), 0, $e);
            }

            $status = $response->getStatusCode();
            $mayRetry = in_array($status, self::RETRYABLE_STATUSES, true)
                && $replayable
                && $attempt < $this->config->maxRetries
                && ($status === 429 || $idempotent);
            if ($mayRetry) {
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

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // A 2xx carrying a non-JSON body (an intermediary HTML/error page slipping
            // through) must still surface as an SDK exception, not a bare \JsonException
            // that escapes the documented Api2ConvertException hierarchy.
            throw new NetworkException(
                'API2Convert returned a non-JSON success response: ' . $e->getMessage(),
                0,
                $e,
            );
        }

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

        if ($retry !== null && $retry > 0) {
            // Honor a positive Retry-After, but never sleep longer than our own ceiling
            // — a huge/hostile value must not stall the caller for hours. Not jittered:
            // the server asked for this exact delay. A zero/past value falls through to
            // the jittered exponential backoff so we never retry-storm with no delay.
            $seconds = min(self::MAX_RETRY_AFTER_SECONDS, (float) $retry);
        } else {
            $seconds = $this->jitter(min(self::MAX_BACKOFF_SECONDS, 0.5 * (2 ** $attempt)));
        }

        ($this->sleeper)($seconds);
    }

    /**
     * Parse a `Retry-After` header value into whole seconds. Supports both the
     * delay-seconds form (`120`) and the HTTP-date form (`Wed, 21 Oct 2015 07:28:00 GMT`).
     * Returns null when absent/unparseable; never negative.
     */
    private function parseRetryAfter(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    /**
     * Add a small upward jitter (0–25%) so correlated clients don't retry/poll in
     * lockstep. Upward-only, so a jittered delay is never shorter than requested.
     */
    private function jitter(float $seconds): float
    {
        return $seconds + $seconds * 0.25 * (mt_rand() / mt_getrandmax());
    }

    /**
     * A request is safe to retry only if its body can be re-read from the start,
     * i.e. it is seekable (an empty request body is seekable too). A non-seekable
     * body — a pipe/socket, possibly reporting size 0 via fstat — must never be
     * replayed, or the retry re-sends a truncated/exhausted body.
     */
    private function isReplayable(RequestInterface $request): bool
    {
        return $request->getBody()->isSeekable();
    }

    /**
     * Whether a request is safe to auto-retry after a 5xx or network failure. GET,
     * HEAD, PUT, DELETE, OPTIONS and TRACE are idempotent by HTTP semantics; a
     * request of any method carrying an `Idempotency-Key` is retry-safe too (the
     * backend deduplicates it). Everything else — notably a bare POST — is not, so a
     * transient error surfaces as an exception instead of risking a duplicate job.
     */
    private function isIdempotent(RequestInterface $request): bool
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'], true)) {
            return true;
        }

        return $request->getHeaderLine('Idempotency-Key') !== '';
    }

    private function rewind(RequestInterface $request): void
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
    }
}
