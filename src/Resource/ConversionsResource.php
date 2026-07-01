<?php

declare(strict_types=1);

namespace Api2Convert\Resource;

use Api2Convert\Http\Transport;

/**
 * The conversions catalog (`GET /conversions`) — the source of truth for which
 * targets exist and which options each accepts. No authentication needed.
 *
 * Use {@see options()} to discover the valid `options` for a target before
 * building a conversion.
 */
final class ConversionsResource
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * List supported conversions, optionally filtered by category and/or target.
     *
     * @return list<array<string, mixed>> Each entry: `{ id, category, target, options }`.
     */
    public function list(?string $category = null, ?string $target = null, int $page = 1): array
    {
        $query = ['page' => (string) $page];
        if ($category !== null) {
            $query['category'] = $category;
        }
        if ($target !== null) {
            $query['target'] = $target;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter(
            $this->transport->request('GET', '/conversions', null, $query),
            'is_array',
        ));

        return $rows;
    }

    /**
     * The option schema (type / enum / default / range) for a single target.
     * `$category` is optional — pass it only to disambiguate an ambiguous target.
     *
     * @return array<string, mixed>
     */
    public function options(string $target, ?string $category = null): array
    {
        $rows = $this->list($category, $target);
        $first = $rows[0] ?? [];
        $options = $first['options'] ?? [];

        return is_array($options) ? $options : [];
    }
}
