<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Api2Convert;
use Api2Convert\Exception\SignatureVerificationException;
use PHPUnit\Framework\TestCase;

final class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test';

    public function testConstructEventVerifiesValidSignature(): void
    {
        $payload = json_encode(['id' => 'job-1', 'status' => ['code' => 'completed']], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, self::SECRET);

        $event = Api2Convert::webhooks()->constructEvent($payload, $signature, self::SECRET);

        self::assertSame('job-1', $event->job->id);
        self::assertTrue($event->job->isCompleted());
    }

    public function testRejectsTamperedPayload(): void
    {
        $payload = json_encode(['id' => 'job-1', 'status' => ['code' => 'completed']], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, self::SECRET);
        $tampered = $payload . ' ';

        $this->expectException(SignatureVerificationException::class);
        Api2Convert::webhooks()->constructEvent($tampered, $signature, self::SECRET);
    }

    public function testRejectsMissingSignatureWhenSecretGiven(): void
    {
        $this->expectException(SignatureVerificationException::class);
        Api2Convert::webhooks()->constructEvent('{}', null, self::SECRET);
    }

    public function testParseSkipsVerificationWithEmptySecret(): void
    {
        $payload = json_encode(['id' => 'job-2', 'status' => ['code' => 'processing']], JSON_THROW_ON_ERROR);

        $event = Api2Convert::webhooks()->constructEvent($payload, null, '');

        self::assertSame('job-2', $event->job->id);
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(SignatureVerificationException::class);
        Api2Convert::webhooks()->parse('not-json');
    }
}
