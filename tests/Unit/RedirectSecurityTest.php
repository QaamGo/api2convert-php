<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Exception\NetworkException;
use Api2Convert\Model\OutputFile;
use Api2Convert\Tests\TestCase;
use GuzzleHttp\Psr7\Response;

/**
 * Secrets ride in custom `X-Oc-*` headers, which HTTP redirect handlers forward
 * across a cross-host hop. These tests pin the two-mode redirect policy: an
 * authenticated / secret-bearing request never follows a 3xx (so the secret can
 * never reach the redirect target), while a plain passwordless download may follow
 * a legitimate storage/CDN redirect.
 */
final class RedirectSecurityTest extends TestCase
{
    public function testAuthenticatedRedirectIsSurfacedAndApiKeyNotResent(): void
    {
        // A 3xx on the authenticated JSON path is never followed (so X-Oc-Api-Key
        // can't reach the redirect target) and is surfaced as a typed error rather
        // than silently decoding the redirect body into an empty model. Without the
        // interpret() 3xx guard this call would return an empty Job and this test
        // would fail on the missing exception.
        $this->http->addResponse(new Response(
            302,
            ['Location' => 'https://evil.example.com/steal', 'Content-Type' => 'application/json'],
            '{}',
        ));

        try {
            $this->client(['maxRetries' => 0])->jobs()->get('job-x');
            self::fail('Expected a NetworkException for the unfollowed redirect on the JSON path.');
        } catch (NetworkException) {
            // expected
        }

        $requests = $this->http->getRequests();
        self::assertCount(1, $requests, 'The redirect must not be followed for an authenticated request.');
        self::assertSame('api.api2convert.com', $requests[0]->getUri()->getHost());
        self::assertSame('test-key', $requests[0]->getHeaderLine('X-Oc-Api-Key'));
    }

    public function testPasswordBearingDownloadDoesNotLeakPasswordCrossHost(): void
    {
        // A download carrying X-Oc-Download-Password must refuse to follow a redirect
        // and surface it as a typed error — never a second request to the new host.
        $this->http->addResponse(new Response(302, ['Location' => 'https://evil.example.com/steal']));
        $output = new OutputFile(id: 'o', uri: 'https://dl.example.com/file', filename: 'result.pdf');

        try {
            $this->client(['maxRetries' => 0])->download($output, 's3cr3t')->contents();
            self::fail('Expected a NetworkException for the unfollowed secret-bearing redirect.');
        } catch (NetworkException) {
            // expected
        }

        $requests = $this->http->getRequests();
        self::assertCount(1, $requests, 'A secret-bearing download must not follow the redirect.');
        self::assertSame('dl.example.com', $requests[0]->getUri()->getHost());
        self::assertSame('s3cr3t', $requests[0]->getHeaderLine('X-Oc-Download-Password'));
    }

    public function testPasswordlessDownloadFollowsStorageRedirect(): void
    {
        // The one legitimate case: a plain, self-contained download following a CDN hop.
        $this->http->addResponse(new Response(302, ['Location' => 'https://cdn.example.com/real']));
        $this->http->addResponse(new Response(200, [], 'PDF-BYTES'));
        $output = new OutputFile(id: 'o', uri: 'https://dl.example.com/file', filename: 'result.pdf');

        $bytes = $this->client(['maxRetries' => 0])->download($output)->contents();

        self::assertSame('PDF-BYTES', $bytes);
        $requests = $this->http->getRequests();
        self::assertCount(2, $requests);
        self::assertSame('https://cdn.example.com/real', (string) $requests[1]->getUri());
        self::assertSame('', $requests[1]->getHeaderLine('X-Oc-Download-Password'));
    }

    public function testPasswordlessDownloadWithUnresolvableRedirectRaisesNetworkException(): void
    {
        // A 3xx with no usable Location cannot resolve to a file — surface it rather
        // than writing the redirect body to disk as a silently-corrupt download.
        $this->http->addResponse(new Response(302, []));
        $output = new OutputFile(id: 'o', uri: 'https://dl.example.com/file', filename: 'result.pdf');

        $this->expectException(NetworkException::class);
        $this->client(['maxRetries' => 0])->download($output)->contents();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('relativeLocationProvider')]
    public function testPasswordlessDownloadResolvesRelativeLocations(
        string $base,
        string $location,
        string $expected,
    ): void {
        $this->http->addResponse(new Response(302, ['Location' => $location]));
        $this->http->addResponse(new Response(200, [], 'BYTES'));
        $output = new OutputFile(id: 'o', uri: $base, filename: 'result.pdf');

        $bytes = $this->client(['maxRetries' => 0])->download($output)->contents();

        self::assertSame('BYTES', $bytes);
        $requests = $this->http->getRequests();
        self::assertSame($expected, (string) $requests[1]->getUri(), "Location: {$location}");
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function relativeLocationProvider(): array
    {
        return [
            'absolute'          => ['https://dl.example.com/a/file', 'https://cdn.example.com/x', 'https://cdn.example.com/x'],
            'root-relative'     => ['https://dl.example.com/a/file', '/real',                    'https://dl.example.com/real'],
            'path-relative'     => ['https://dl.example.com/a/file', 'real',                     'https://dl.example.com/a/real'],
            'protocol-relative' => ['https://dl.example.com/a/file', '//cdn.example.com/x',      'https://cdn.example.com/x'],
        ];
    }
}
