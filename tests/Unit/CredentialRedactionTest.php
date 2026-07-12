<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Enum\CloudProvider;
use Api2Convert\Exception\ValidationException;
use Api2Convert\Input\CloudInput;
use Api2Convert\Model\OutputTarget;
use Api2Convert\Tests\TestCase;

/**
 * Cloud-connector fixture 3 — the credential redaction / isolation suite.
 *
 * The single secret `SUPERSECRET123` must never appear on any rendering/error path, and the fixed
 * marker `[REDACTED]` must appear where a credentials object is rendered.
 */
final class CredentialRedactionTest extends TestCase
{
    private const SECRET = 'SUPERSECRET123';
    private const MARKER = '[REDACTED]';

    // ---- 3a: object rendering --------------------------------------------------------------

    public function testCloudInputToStringMasksCredentials(): void
    {
        $rendered = (string) CloudInput::amazonS3(
            bucket: 'b',
            file: 'f',
            accesskeyid: 'AKIA',
            secretaccesskey: self::SECRET,
        );

        self::assertStringNotContainsString(self::SECRET, $rendered);
        self::assertStringContainsString(self::MARKER, $rendered);
        // Non-secret parameters still render.
        self::assertStringContainsString('"bucket":"b"', $rendered);
    }

    public function testOutputTargetToStringMasksCredentials(): void
    {
        $rendered = (string) OutputTarget::of(
            CloudProvider::Ftp,
            ['host' => 'ftp.example.com'],
            ['username' => 'u', 'password' => self::SECRET],
        );

        self::assertStringNotContainsString(self::SECRET, $rendered);
        self::assertStringContainsString(self::MARKER, $rendered);
    }

    // ---- 3b + 3c: error text and error-body deep-walk --------------------------------------

    public function testCreatePathErrorNeverLeaksSubmittedCredential(): void
    {
        // A 422 whose decoded body echoes the submitted secret in a nested/dotted key (belt-and-
        // suspenders: the real API echoes field *names* only). The convert() request body itself
        // carried the secret in credentials — it must not surface on the exception either.
        $this->http->addResponse($this->jsonResponse(422, [
            'message' => 'Validation failed',
            'errors' => ['input.0.credentials.secretaccesskey' => self::SECRET],
        ]));

        try {
            $this->client(['maxRetries' => 0])->convert(
                CloudInput::amazonS3('b', 'f', 'AKIA', self::SECRET),
                'jpg',
            );
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            // 3b: no secret in the message or anywhere on the exception.
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            // 3c: the deep-walk masks the echoed secret to the marker.
            $body = (string) json_encode($e->body);
            self::assertStringNotContainsString(self::SECRET, $body);
            self::assertStringContainsString(self::MARKER, $body);
        }
    }

    // ---- 3d: sensitive parameters leaf -----------------------------------------------------

    public function testSensitiveParametersLeafIsMaskedInRendering(): void
    {
        $rendered = (string) CloudInput::of(
            CloudProvider::AmazonS3,
            ['token' => 'PARAMSECRET', 'bucket' => 'b'],
        );

        self::assertStringNotContainsString('PARAMSECRET', $rendered);
        self::assertStringContainsString(self::MARKER, $rendered);
        // A non-secret key renders normally.
        self::assertStringContainsString('"bucket":"b"', $rendered);
    }
}
