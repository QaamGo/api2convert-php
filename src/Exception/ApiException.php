<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * Thrown when the API returns an HTTP error response (status >= 400).
 *
 * Specific status codes are mapped to dedicated subclasses
 * (see {@see AuthenticationException}, {@see ValidationException},
 * {@see RateLimitException}, {@see NotFoundException},
 * {@see PaymentRequiredException}, {@see ServerException}); this base type is
 * used for any 4xx that has no more specific subclass.
 */
class ApiException extends Api2ConvertException
{
    /**
     * @param string               $message    Error message from the API (the `message` field) or a fallback.
     * @param int                  $statusCode The HTTP status code of the response.
     * @param string|null          $requestId  Value of the `X-Request-Id` header, if any. Quote it in support requests.
     * @param array<string, mixed> $body       The decoded JSON error body, when available.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $requestId = null,
        public readonly array $body = [],
    ) {
        parent::__construct($message, $statusCode);
    }
}
