<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Exception\ConversionFailedException;
use Api2Convert\Exception\TimeoutException;
use Api2Convert\Tests\TestCase;

final class WaitTest extends TestCase
{
    public function testPollsUntilCompleted(): void
    {
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'incomplete']]));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'processing']]));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'completed']]));

        $job = $this->client()->jobs()->wait('j');

        self::assertTrue($job->isCompleted());
        self::assertCount(3, $this->http->getRequests());
    }

    public function testThrowsConversionFailedWithJobErrors(): void
    {
        $this->http->addResponse($this->jsonResponse(200, [
            'id' => 'j',
            'status' => ['code' => 'failed', 'info' => 'The conversion failed.'],
            'errors' => [['code' => 4000, 'message' => 'The input file could not be processed.']],
        ]));

        try {
            $this->client()->jobs()->wait('j');
            self::fail('Expected ConversionFailedException');
        } catch (ConversionFailedException $e) {
            self::assertCount(1, $e->errors());
            self::assertSame(4000, $e->errors()[0]->code);
            self::assertStringContainsString('could not be processed', $e->errors()[0]->message);
            self::assertTrue($e->job->isFailed());
        }
    }

    public function testReturnsFailedJobWhenThrowOnFailureDisabled(): void
    {
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'failed']]));

        $job = $this->client()->jobs()->wait('j', throwOnFailure: false);

        self::assertTrue($job->isFailed());
    }

    public function testTimesOut(): void
    {
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'incomplete']]));

        $this->expectException(TimeoutException::class);
        $this->client(['pollTimeout' => 0])->jobs()->wait('j');
    }
}
