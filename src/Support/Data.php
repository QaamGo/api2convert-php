<?php

declare(strict_types=1);

namespace Api2Convert\Support;

/**
 * Typed accessors over a decoded JSON object (`array<string, mixed>`).
 *
 * Keeps model hydration null-safe and free of scattered casts. Internal helper —
 * not part of the public API.
 *
 * @internal
 */
final class Data
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    public static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /**
     * Return $value if it is an associative array, otherwise an empty array.
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Return $value if it is a list, otherwise an empty list.
     *
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Map each associative-array element of $value through $factory.
     *
     * @template T
     * @param callable(array<array-key, mixed>): T $factory
     * @return list<T>
     */
    public static function mapObjects(mixed $value, callable $factory): array
    {
        $out = [];
        foreach (self::list($value) as $item) {
            if (is_array($item)) {
                $out[] = $factory($item);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        $out = [];
        foreach (self::list($value) as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
