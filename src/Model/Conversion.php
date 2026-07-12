<?php

declare(strict_types=1);

namespace Api2Convert\Model;

use Api2Convert\Support\Data;

/**
 * A single conversion within a job: the target format plus its options.
 */
final class Conversion
{
    /**
     * @param array<string, mixed> $options       Target-specific options (discover them via `$client->options()`).
     * @param array<string, mixed> $metadata      Free-form metadata echoed back by the API.
     * @param list<OutputTarget>   $outputTargets Cloud delivery targets for this conversion's output, if any.
     */
    public function __construct(
        public readonly string $target,
        public readonly ?string $id = null,
        public readonly ?string $category = null,
        public readonly array $options = [],
        public readonly array $metadata = [],
        public readonly array $outputTargets = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::string($data['target'] ?? null),
            Data::nullableString($data['id'] ?? null),
            Data::nullableString($data['category'] ?? null),
            Data::object($data['options'] ?? null),
            Data::object($data['metadata'] ?? null),
            Data::mapObjects($data['output_target'] ?? null, OutputTarget::fromArray(...)),
        );
    }
}
