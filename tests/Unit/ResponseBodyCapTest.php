<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Exception\NetworkException;
use Api2Convert\Tests\TestCase;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;

/**
 * The control-plane (API JSON success body + error body) read is capped at 16 MiB,
 * mirroring the Go SDK's `maxResponseBytes`. A hostile or buggy server must not be
 * able to force an unbounded in-memory read (OOM) on these paths — the file-download
 * path is streamed to disk and bounded separately.
 *
 * The bodies here are backed by a {@see PumpStream} that would yield far more than
 * the cap on demand, so the tests also prove the read is *bounded*: only ~16 MiB is
 * ever pulled from the stream, never the (simulated) 512 MiB it could produce.
 */
final class ResponseBodyCapTest extends TestCase
{
    private const CAP = 16 * 1024 * 1024;

    /** A body the SDK must never buffer in full — 512 MiB if read unboundedly. */
    private const HUGE = 512 * 1024 * 1024;

    public function testOverCapSuccessBodyRaisesNetworkExceptionAndIsNotBufferedUnboundedly(): void
    {
        $read = 0;
        $this->http->addResponse(new Response(200, [], $this->pump($read)));

        try {
            $this->client(['maxRetries' => 0])->jobs()->get('job-x');
            self::fail('Expected NetworkException for an over-cap success body');
        } catch (NetworkException $e) {
            self::assertStringContainsString('16 MiB', $e->getMessage());
        }

        // The read stops just past the cap — never anywhere near the 512 MiB the
        // stream could have produced, proving the cap is effective (no OOM).
        self::assertLessThanOrEqual(self::CAP + 4096, $read);
    }

    public function testOverCapErrorBodyRaisesNetworkExceptionAndIsNotBufferedUnboundedly(): void
    {
        $read = 0;
        $this->http->addResponse(new Response(500, [], $this->pump($read)));

        try {
            $this->client(['maxRetries' => 0])->jobs()->get('job-x');
            self::fail('Expected NetworkException for an over-cap error body');
        } catch (NetworkException $e) {
            self::assertStringContainsString('16 MiB', $e->getMessage());
        }

        self::assertLessThanOrEqual(self::CAP + 4096, $read);
    }

    /**
     * A stream that yields up to {@see HUGE} bytes on demand while counting how many
     * bytes are actually pulled, without ever materialising the whole body. Reading
     * it in full would allocate 512 MiB; a bounded reader pulls only ~16 MiB.
     */
    private function pump(int &$read): PumpStream
    {
        return new PumpStream(static function (int $length) use (&$read): string|false {
            if ($read >= self::HUGE) {
                return false;
            }
            $take = (int) min($length, self::HUGE - $read);
            $read += $take;

            return str_repeat('a', $take);
        });
    }
}
