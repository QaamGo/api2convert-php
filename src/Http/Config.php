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

    /**
     * Hard floor for the job-poll interval. A caller-supplied 0 or negative value
     * is raised to this so the poll loop can never busy-spin the API (see the 2017
     * `sleep(0)` self-DDOS fix in the legacy SDK).
     */
    public const MIN_POLL_INTERVAL = 0.5;

    /**
     * Hard ceiling for the total job-poll timeout (4 hours), mirroring the legacy
     * SDK's MAX_WAITING_TIME. A misconfigured or hostile-large timeout degrades to a
     * bounded wait instead of an unbounded poll.
     */
    public const MAX_POLL_TIMEOUT = 14400;

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
        // Clamp the poll knobs so a caller value can neither busy-loop (interval floor)
        // nor poll unbounded (timeout ceiling), and the max interval is never below the
        // starting interval. This is the single entry point the client uses.
        $pollInterval = max(self::MIN_POLL_INTERVAL, (float) ($options['pollInterval'] ?? 1.0));
        $pollMaxInterval = max($pollInterval, (float) ($options['pollMaxInterval'] ?? 5.0));
        $pollTimeout = min(self::MAX_POLL_TIMEOUT, max(0, (int) ($options['pollTimeout'] ?? 300)));

        return new self(
            apiKey: $apiKey,
            baseUrl: rtrim($options['baseUrl'] ?? self::DEFAULT_BASE_URL, '/'),
            timeout: max(1, (int) ($options['timeout'] ?? 30)),
            maxRetries: max(0, (int) ($options['maxRetries'] ?? 2)),
            pollInterval: $pollInterval,
            pollMaxInterval: $pollMaxInterval,
            pollTimeout: $pollTimeout,
        );
    }
}
