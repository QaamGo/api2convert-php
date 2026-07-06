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

    /** How many redirects a passwordless download may follow before giving up. */
    private const MAX_DOWNLOAD_REDIRECTS = 5;

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
        $request = $this->createRequest($method, $this->url($path, $query))
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

        // The default client is built with redirects disabled so a secret header
        // (X-Oc-Api-Key / X-Oc-Token) can never ride a cross-host 3xx. On this JSON
        // path that leaves an un-followed redirect as a <400 "success" whose body
        // would decode to an empty model — surface it as a typed error instead of
        // silently returning nothing. (The download path handles 3xx itself.)
        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            throw new NetworkException(
                "API2Convert returned an unexpected redirect (HTTP {$status}); the request was not followed.",
            );
        }

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
     * A request carrying any `X-Oc-*` secret header (e.g. a download password) must
     * not follow redirects: the default client is built with `allow_redirects` off
     * so a secret can never ride a cross-host 3xx (Guzzle would forward custom
     * headers across the hop). A plain, passwordless download may follow a legitimate
     * storage/CDN redirect, which we do manually here — dropping any `X-Oc-*` header
     * on a cross-origin hop as a belt-and-suspenders guard. When a secret-bearing
     * request is redirected the 3xx is surfaced as a {@see NetworkException} so a
     * redirect body never lands on disk as a silently-corrupt file.
     *
     * @param array<string, string> $headers
     */
    public function download(string $uri, array $headers = []): \Psr\Http\Message\StreamInterface
    {
        $carriesSecret = $this->carriesSecret($headers);
        $maxRedirects = $carriesSecret ? 0 : self::MAX_DOWNLOAD_REDIRECTS;

        $currentUri = $uri;
        $currentHeaders = $headers;

        for ($hop = 0; true; $hop++) {
            $request = $this->createRequest('GET', $currentUri);
            foreach ($currentHeaders as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            $response = $this->send($request);
            $this->ensureSuccessful($response);

            $status = $response->getStatusCode();
            if ($status < 300 || $status >= 400) {
                return $response->getBody();
            }

            // A 3xx here means either a secret-bearing request (which we refuse to
            // follow) or a passwordless download that has exhausted its redirect
            // budget. Either way, returning the redirect body would write a corrupt
            // file — surface it as a typed error instead.
            $location = $response->getHeaderLine('Location');
            if ($hop >= $maxRedirects || $location === '') {
                throw new NetworkException(
                    'The download did not resolve: a redirect was not followed'
                    . ($carriesSecret ? ' because the request carried a secret header.' : '.')
                );
            }

            $nextUri = $this->resolveLocation($currentUri, $location);
            if ($this->sameOrigin($currentUri, $nextUri) === false) {
                $currentHeaders = $this->stripSecretHeaders($currentHeaders);
            }
            $currentUri = $nextUri;
        }
    }

    /**
     * Percent-encode a single dynamic path segment (job/preset id, stats period or
     * filter) so a value containing `/`, `?`, `#` or a space cannot break out of its
     * segment and reshape the request path. The fixed `/` separators the caller
     * writes between segments are left intact — only the interpolated value is passed
     * here. Query parameters are encoded separately by {@see url()}.
     */
    public static function segment(string $value): string
    {
        return rawurlencode($value);
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

    /**
     * Build a PSR-7 request, wrapping a malformed URL so it surfaces as a typed
     * {@see NetworkException} inside the SDK hierarchy rather than a raw
     * \InvalidArgumentException escaping from the PSR-17 factory / URI parser.
     */
    private function createRequest(string $method, string $uri): RequestInterface
    {
        try {
            return $this->requestFactory->createRequest($method, $uri);
        } catch (\InvalidArgumentException $e) {
            throw new NetworkException('Invalid request URL: ' . $uri, 0, $e);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function carriesSecret(array $headers): bool
    {
        foreach (array_keys($headers) as $name) {
            if (stripos($name, 'x-oc-') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop every `X-Oc-*` secret header so it cannot ride a cross-origin redirect.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function stripSecretHeaders(array $headers): array
    {
        return array_filter(
            $headers,
            static fn (string $name): bool => stripos($name, 'x-oc-') !== 0,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Resolve a (possibly relative) `Location` against the URL it came from.
     */
    private function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }

        // A protocol-relative Location ("//host/path") keeps the current scheme but
        // carries its own host — must be handled before the root-relative branch,
        // which would otherwise glue it onto the current origin as a bogus path.
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . $dir . $location;
    }

    /**
     * Whether two URLs share the same scheme + host + port (same origin).
     */
    private function sameOrigin(string $a, string $b): bool
    {
        return $this->origin($a) === $this->origin($b);
    }

    private function origin(string $uri): string
    {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = strtolower($parts['scheme'] . '://' . $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
