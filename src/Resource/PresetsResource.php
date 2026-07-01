<?php

declare(strict_types=1);

namespace Api2Convert\Resource;

use Api2Convert\Http\Transport;
use Api2Convert\Model\Preset;
use Api2Convert\Support\Data;

/**
 * Saved conversion presets (reusable named target + options).
 */
final class PresetsResource
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @return list<Preset>
     */
    public function list(?string $category = null, ?string $target = null, ?string $filter = null): array
    {
        $query = array_filter([
            'category' => $category,
            'target' => $target,
            'filter' => $filter,
        ], static fn (?string $v): bool => $v !== null);

        return Data::mapObjects($this->transport->request('GET', '/presets', null, $query), Preset::fromArray(...));
    }

    /**
     * @param array<string, mixed> $payload `{ name, target, options, scope?, category? }`
     */
    public function create(array $payload): Preset
    {
        return Preset::fromArray($this->transport->request('POST', '/presets', $payload));
    }

    public function get(string $presetId): Preset
    {
        return Preset::fromArray($this->transport->request('GET', "/presets/{$presetId}"));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(string $presetId, array $payload): Preset
    {
        return Preset::fromArray($this->transport->request('PATCH', "/presets/{$presetId}", $payload));
    }

    public function delete(string $presetId): void
    {
        $this->transport->request('DELETE', "/presets/{$presetId}");
    }
}
