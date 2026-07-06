<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Exception\NetworkException;
use Api2Convert\Model\OutputFile;
use Api2Convert\Tests\TestCase;
use GuzzleHttp\Psr7\Response;

final class TransportUrlTest extends TestCase
{
    public function testMalformedBaseUrlRaisesNetworkExceptionOnRequest(): void
    {
        // A malformed URL must stay inside the SDK hierarchy, not leak a raw
        // \InvalidArgumentException from the PSR-17 URI parser.
        $client = $this->client(['baseUrl' => 'http://exa mple.com/v2']);

        $this->expectException(NetworkException::class);
        $client->jobs()->get('job-x');
    }

    public function testMalformedDownloadUrlRaisesNetworkException(): void
    {
        $output = new OutputFile(id: 'o', uri: 'http://exa mple.com/file', filename: 'result.pdf');

        $this->expectException(NetworkException::class);
        $this->client()->download($output)->contents();
    }

    public function testMalformedUrlIsNotARawInvalidArgumentException(): void
    {
        $thrown = null;
        try {
            $this->client(['baseUrl' => 'http://exa mple.com/v2'])->jobs()->get('job-x');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotInstanceOf(\InvalidArgumentException::class, $thrown);
        self::assertInstanceOf(NetworkException::class, $thrown);
    }

    public function testDynamicPathSegmentIsPercentEncoded(): void
    {
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'job-1']));

        // A job id containing "/", "?" and "#" must not break out of its path segment.
        $this->client(['maxRetries' => 0])->jobs()->get('a/b?c#d');

        $path = $this->requestAt(0)->getUri()->getPath();
        self::assertStringContainsString('a%2Fb%3Fc%23d', $path);
        self::assertStringNotContainsString('a/b?c#d', (string) $this->requestAt(0)->getUri());
    }

    public function testStatsSegmentsAreEncoded(): void
    {
        $this->http->addResponse($this->jsonResponse(200, []));

        $this->client(['maxRetries' => 0])->stats()->day('2020/01', 'a/b');

        $uri = (string) $this->requestAt(0)->getUri();
        self::assertStringContainsString('/stats/day/2020%2F01/a%2Fb', $uri);
    }

    public function testPresetSegmentIsEncoded(): void
    {
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'p1']));

        $this->client(['maxRetries' => 0])->presets()->get('a/b#c');

        $path = $this->requestAt(0)->getUri()->getPath();
        self::assertStringContainsString('/presets/a%2Fb%23c', $path);
    }

    public function testNormalIdIsUnaffectedByEncoding(): void
    {
        // A normal opaque id (hex/uuid-ish) must pass through unchanged.
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'job-1']));

        $this->client(['maxRetries' => 0])->jobs()->get('abc123-DEF');

        self::assertStringContainsString('/jobs/abc123-DEF', (string) $this->requestAt(0)->getUri());
    }
}
