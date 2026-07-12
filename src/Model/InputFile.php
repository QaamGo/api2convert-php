<?php

declare(strict_types=1);

namespace Api2Convert\Model;

use Api2Convert\Support\Data;

/**
 * An input file attached to a job.
 */
final class InputFile
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $parameters Cloud-input locator keys (`bucket`, `file`, `host`, …); empty for
     *                                          non-cloud inputs. Credentials are never surfaced on read.
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $type,
        public readonly ?string $source = null,
        public readonly ?string $status = null,
        public readonly ?string $filename = null,
        public readonly ?int $size = null,
        public readonly ?string $contentType = null,
        public readonly array $options = [],
        public readonly array $parameters = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::nullableString($data['id'] ?? null),
            Data::string($data['type'] ?? null),
            Data::nullableString($data['source'] ?? null),
            Data::nullableString($data['status'] ?? null),
            Data::nullableString($data['filename'] ?? null),
            Data::nullableInt($data['size'] ?? null),
            Data::nullableString($data['content_type'] ?? null),
            Data::object($data['options'] ?? null),
            Data::object($data['parameters'] ?? null),
        );
    }
}
