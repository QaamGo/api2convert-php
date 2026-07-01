<?php

declare(strict_types=1);

namespace Api2Convert\Model;

use Api2Convert\Support\Data;

/**
 * A saved conversion preset (a reusable named set of target + options).
 */
final class Preset
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly ?string $target = null,
        public readonly ?string $category = null,
        public readonly ?string $scope = null,
        public readonly array $options = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::nullableString($data['id'] ?? null),
            Data::string($data['name'] ?? null),
            Data::nullableString($data['target'] ?? null),
            Data::nullableString($data['category'] ?? null),
            Data::nullableString($data['scope'] ?? null),
            Data::object($data['options'] ?? null),
        );
    }
}
