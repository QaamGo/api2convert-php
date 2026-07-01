<?php

declare(strict_types=1);

namespace Api2Convert\Model;

use Api2Convert\Support\Data;

/**
 * A produced output file. {@see $uri} is a self-contained download URL (no auth),
 * valid for a limited time (24h by default).
 */
final class OutputFile
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $uri,
        public readonly ?string $filename = null,
        public readonly ?int $size = null,
        public readonly ?string $status = null,
        public readonly ?string $contentType = null,
        public readonly ?string $checksum = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::nullableString($data['id'] ?? null),
            Data::string($data['uri'] ?? null),
            Data::nullableString($data['filename'] ?? null),
            Data::nullableInt($data['size'] ?? null),
            Data::nullableString($data['status'] ?? null),
            Data::nullableString($data['content_type'] ?? null),
            Data::nullableString($data['checksum'] ?? null),
            Data::object($data['metadata'] ?? null),
        );
    }
}
