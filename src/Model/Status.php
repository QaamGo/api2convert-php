<?php

declare(strict_types=1);

namespace Api2Convert\Model;

use Api2Convert\Support\Data;

/**
 * A job's status: a machine-readable {@see $code} plus an optional human {@see $info}.
 */
final class Status
{
    public function __construct(
        public readonly string $code,
        public readonly ?string $info = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::string($data['code'] ?? null),
            Data::nullableString($data['info'] ?? null),
        );
    }
}
