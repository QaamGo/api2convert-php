<?php

declare(strict_types=1);

namespace Api2Convert\Model;

use Api2Convert\Enum\CloudProvider;
use Api2Convert\Support\Data;
use Api2Convert\Support\Redactor;
use Stringable;

/**
 * A cloud-storage delivery target for a conversion's output:
 * `{ type:<provider>, parameters, credentials }`.
 *
 * Attach one (or more) to a conversion via `$client->convert(..., outputTargets: [...])` /
 * `convertAsync(...)`, or inline in a raw `jobs()->create()` conversion map. When any output target
 * is set the conversion delivers straight to your storage and produces **no** local output — so
 * `convert()` returns the completed job without downloading.
 *
 * This wave ships the **generic** shape only (`type` + free-form `parameters`/`credentials`); the
 * per-provider output keys live in a separate service and diverge per provider, so there are no
 * per-provider output factories yet.
 *
 * Serialization ({@see toArray()}) emits `{ type, parameters, credentials }` and **omits `status`**
 * (server-set, read-only). On read ({@see fromArray()}) `type`, `parameters` and `status` round-trip
 * as raw values; `credentials` are **never** surfaced (the API returns them empty). `credentials`
 * ride in the plaintext body, so {@see __toString()} masks the whole object to `[REDACTED]`.
 */
final class OutputTarget implements Stringable
{
    /**
     * @param array<string, mixed> $parameters  Delivery locator keys (provider-specific).
     * @param array<string, mixed> $credentials Secret keys (never surfaced on read).
     * @param string|null          $status      Server-set delivery status on read
     *                                           (`waiting|uploading|completed|failed`); never sent on create.
     */
    public function __construct(
        public readonly string $type,
        public readonly array $parameters = [],
        public readonly array $credentials = [],
        public readonly ?string $status = null,
    ) {
    }

    /**
     * Generic constructor accepting a typed provider or a forward-compat string.
     *
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $credentials
     */
    public static function of(CloudProvider|string $type, array $parameters = [], array $credentials = []): self
    {
        return new self(
            $type instanceof CloudProvider ? $type->value : $type,
            $parameters,
            $credentials,
        );
    }

    /**
     * The wire descriptor sent on create — `{ type, parameters, credentials }`, with `status` omitted
     * (it is server-set and read-only).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'parameters' => $this->parameters,
            'credentials' => $this->credentials,
        ];
    }

    /**
     * Hydrate from a `GET /jobs/{id}` `output_target[]` element. `type`/`status` stay raw strings (an
     * unknown provider round-trips untyped); `credentials` are deliberately not surfaced.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::string($data['type'] ?? null),
            Data::object($data['parameters'] ?? null),
            [],
            Data::nullableString($data['status'] ?? null),
        );
    }

    /**
     * Human-readable form with credentials masked — safe to log.
     */
    public function __toString(): string
    {
        return sprintf(
            'OutputTarget(type=%s, parameters=%s, credentials=%s, status=%s)',
            $this->type,
            (string) json_encode(Redactor::parameters($this->parameters)),
            Redactor::MARKER,
            $this->status ?? 'null',
        );
    }
}
