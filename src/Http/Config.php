<?php

declare(strict_types=1);

namespace Api2Convert\Http;

/**
 * Immutable client configuration. Construct via {@see Config::create()} so you only
 * set what you need; everything has a sensible default.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.api2convert.com/v2';

    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        /** Per-request network timeout, in seconds. */
        public readonly int $timeout = 30,
        /** Automatic retries for transient failures (429 / 5xx / network). */
        public readonly int $maxRetries = 2,
        /** First poll interval when waiting for a job, in seconds. */
        public readonly float $pollInterval = 1.0,
        /** Upper bound the poll interval backs off to, in seconds. */
        public readonly float $pollMaxInterval = 5.0,
        /** How long to wait for a job to finish before giving up, in seconds. */
        public readonly int $pollTimeout = 300,
    ) {
    }

    /**
     * @param array{
     *     baseUrl?: string,
     *     timeout?: int,
     *     maxRetries?: int,
     *     pollInterval?: float,
     *     pollMaxInterval?: float,
     *     pollTimeout?: int
     * } $options
     */
    public static function create(string $apiKey, array $options = []): self
    {
        return new self(
            apiKey: $apiKey,
            baseUrl: rtrim($options['baseUrl'] ?? self::DEFAULT_BASE_URL, '/'),
            timeout: $options['timeout'] ?? 30,
            maxRetries: $options['maxRetries'] ?? 2,
            pollInterval: $options['pollInterval'] ?? 1.0,
            pollMaxInterval: $options['pollMaxInterval'] ?? 5.0,
            pollTimeout: $options['pollTimeout'] ?? 300,
        );
    }
}
