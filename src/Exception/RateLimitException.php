<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * Too many requests (HTTP 429). The client already retries these automatically
 * with backoff; this is thrown only once retries are exhausted.
 */
class RateLimitException extends ApiException
{
    /**
     * @param int|null $retryAfter Seconds to wait before retrying, from the `Retry-After` header, if provided.
     */
    public function __construct(
        string $message,
        int $statusCode = 429,
        ?string $requestId = null,
        array $body = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode, $requestId, $body);
    }
}
