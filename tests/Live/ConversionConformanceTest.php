<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Live;

use Api2Convert\Api2Convert;
use Api2Convert\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end conformance against the real API. Skipped unless API2CONVERT_API_KEY
 * is set, so it is safe to keep in the default suite.
 *
 * Run against a specific host with:
 *   API2CONVERT_API_KEY=... API2CONVERT_BASE_URL=https://api.api2convert.com/v2 vendor/bin/phpunit --testsuite live
 */
final class ConversionConformanceTest extends TestCase
{
    private function client(): Api2Convert
    {
        $key = getenv('API2CONVERT_API_KEY');
        if ($key === false || $key === '') {
            self::markTestSkipped('Set API2CONVERT_API_KEY to run live conformance tests.');
        }

        $baseUrl = getenv('API2CONVERT_BASE_URL');

        return new Api2Convert($key, $baseUrl !== false && $baseUrl !== '' ? ['baseUrl' => $baseUrl] : []);
    }

    public function testConvertsRemoteImageToPng(): void
    {
        $result = $this->client()->convert(
            'https://example-files.online-convert.com/raster%20image/jpg/example.jpg',
            'png',
        );

        self::assertTrue($result->job->isCompleted());
        $target = sys_get_temp_dir() . '/a2c-live-' . uniqid() . '.png';
        $result->save($target);
        self::assertFileExists($target);
        self::assertGreaterThan(0, filesize($target));
        unlink($target);
    }

    public function testInvalidTargetRaisesValidationError(): void
    {
        // The real API rejects an unknown target synchronously at job creation
        // (HTTP 400 -> ValidationException), not as an async failed job. The
        // failed/canceled-job -> ConversionFailedException path is covered by the
        // unit suite (WaitTest, PollingGuardsTest).
        $this->expectException(ValidationException::class);
        $this->client()->convert(
            'https://example-files.online-convert.com/raster%20image/jpg/example.jpg',
            'this-is-not-a-real-target',
        );
    }
}
