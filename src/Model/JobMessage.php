<?php

declare(strict_types=1);

namespace Api2Convert\Model;

use Api2Convert\Support\Data;

/**
 * An error or warning attached to a job (the `errors[]` / `warnings[]` entries).
 */
final class JobMessage
{
    /**
     * @param array<string, mixed> $details Extra context, when provided by the API.
     */
    public function __construct(
        public readonly ?int $code,
        public readonly string $message,
        public readonly ?string $source = null,
        public readonly ?string $idSource = null,
        public readonly array $details = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::nullableInt($data['code'] ?? null),
            Data::string($data['message'] ?? null),
            Data::nullableString($data['source'] ?? null),
            Data::nullableString($data['id_source'] ?? null),
            Data::object($data['details'] ?? null),
        );
    }
}
