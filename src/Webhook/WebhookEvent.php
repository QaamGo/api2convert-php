<?php

declare(strict_types=1);

namespace Api2Convert\Webhook;

use Api2Convert\Model\Job;

/**
 * A verified webhook callback. The API posts the job whose status changed.
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $payload The full decoded callback body.
     */
    public function __construct(
        public readonly Job $job,
        public readonly array $payload,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(Job::fromArray($payload), $payload);
    }
}
