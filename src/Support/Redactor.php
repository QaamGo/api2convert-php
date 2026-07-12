<?php

declare(strict_types=1);

namespace Api2Convert\Support;

/**
 * Credential redaction for cloud connectors.
 *
 * Cloud `credentials` ride in the plaintext request body, so they must never surface where
 * a value object or an SDK-emitted string could leak them. This helper centralizes the two
 * masks the contract mandates:
 *
 * - the **whole `credentials` object** collapses to {@see MARKER} on every object-inspection
 *   path (`__toString`);
 * - any `parameters` leaf whose key contains a sensitive token
 *   ({@see SENSITIVE_SUBSTRINGS}, case-insensitive substring) collapses to {@see MARKER};
 * - the decoded error body is deep-walked ({@see redactBody()}) as belt-and-suspenders —
 *   the API only ever echoes field *names*, never a credential *value*, but a future
 *   server/proxy change must not be able to leak one.
 *
 * @internal
 */
final class Redactor
{
    /** The fixed, fleet-wide redaction marker (D9). */
    public const MARKER = '[REDACTED]';

    /**
     * Case-insensitive substrings that mark a key as carrying a secret. A key containing any
     * of these has its whole value masked.
     *
     * @var list<string>
     */
    private const SENSITIVE_SUBSTRINGS = [
        'token', 'password', 'passwd', 'secret', 'key', 'keyfile',
        'credential', 'passphrase', 'sas', 'sig', 'signature',
    ];

    /**
     * Whether a key name marks its value as sensitive (case-insensitive substring match).
     */
    public static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_SUBSTRINGS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask sensitive leaves of a `parameters` map: any key matching {@see isSensitiveKey()}
     * has its value replaced by {@see MARKER}; nested maps are walked recursively. Non-secret
     * keys (`bucket`, `host`, `file`, `container`, `projectid`, …) are left untouched.
     *
     * @param array<array-key, mixed> $parameters
     * @return array<array-key, mixed>
     */
    public static function parameters(array $parameters): array
    {
        $out = [];
        foreach ($parameters as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = self::MARKER;
                continue;
            }
            $out[$key] = is_array($value) ? self::parameters($value) : $value;
        }

        return $out;
    }

    /**
     * Deep-walk a decoded error body and mask the value of every sensitive key (including a
     * flattened/dotted key like `input.0.credentials.secretaccesskey`) to {@see MARKER}.
     *
     * @param array<array-key, mixed> $body
     * @return array<array-key, mixed>
     */
    public static function redactBody(array $body): array
    {
        $out = [];
        foreach ($body as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = self::MARKER;
                continue;
            }
            $out[$key] = is_array($value) ? self::redactBody($value) : $value;
        }

        return $out;
    }
}
