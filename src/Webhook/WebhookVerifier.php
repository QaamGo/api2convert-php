<?php

declare(strict_types=1);

namespace Api2Convert\Webhook;

use Api2Convert\Exception\SignatureVerificationException;

/**
 * Verifies and parses webhook callbacks.
 *
 * Pass the **raw** request body (not a re-encoded array) so signature
 * verification is byte-exact. Signature checking uses HMAC-SHA256 and matches the
 * signed-webhooks scheme on the server side; until signed webhooks are enabled on
 * your account no signature is sent — use {@see parse()} then, or call
 * {@see constructEvent()} with an empty secret to skip verification.
 */
final class WebhookVerifier
{
    /**
     * Verify the signature (when a secret is given) and return the typed event.
     *
     * @param string      $payload   The raw request body.
     * @param string|null $signature The signature header value (e.g. `X-Oc-Signature`).
     * @param string      $secret    Your webhook signing secret; pass '' to skip verification.
     *
     * @throws SignatureVerificationException When the signature is missing or does not match.
     */
    public function constructEvent(string $payload, ?string $signature, string $secret): WebhookEvent
    {
        if ($secret !== '') {
            if ($signature === null || $signature === '') {
                throw new SignatureVerificationException('Missing webhook signature header.');
            }

            $expected = hash_hmac('sha256', $payload, $secret);
            if (!hash_equals($expected, $signature)) {
                throw new SignatureVerificationException('Webhook signature verification failed.');
            }
        }

        return $this->parse($payload);
    }

    /**
     * Parse a callback body into a typed event WITHOUT verifying a signature.
     * Only use this when signed webhooks are not yet enabled for your account.
     *
     * @throws SignatureVerificationException When the body is not valid JSON.
     */
    public function parse(string $payload): WebhookEvent
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureVerificationException('Webhook payload is not valid JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new SignatureVerificationException('Webhook payload is not a JSON object.');
        }

        return WebhookEvent::fromArray($decoded);
    }
}
