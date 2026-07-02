<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Api2Convert;
use Api2Convert\Exception\ConversionFailedException;
use Api2Convert\Http\Config;
use Api2Convert\Http\Transport;
use Api2Convert\Tests\TestCase;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;

/**
 * Guards that keep the SDK from hammering the API — the reliability lessons the
 * legacy onlineconvert SDK learned the hard way (interval floor, timeout ceiling,
 * bounded Retry-After, non-replayable-body retries, terminal-state completeness).
 */
final class PollingGuardsTest extends TestCase
{
    public function testPollIntervalIsFlooredToAMinimum(): void
    {
        $config = Config::create('k', ['pollInterval' => 0.0]);
        self::assertGreaterThanOrEqual(Config::MIN_POLL_INTERVAL, $config->pollInterval);

        $negative = Config::create('k', ['pollInterval' => -5.0]);
        self::assertGreaterThanOrEqual(Config::MIN_POLL_INTERVAL, $negative->pollInterval);
    }

    public function testPollMaxIntervalIsNeverBelowTheStartInterval(): void
    {
        $config = Config::create('k', ['pollInterval' => 3.0, 'pollMaxInterval' => 1.0]);
        self::assertGreaterThanOrEqual($config->pollInterval, $config->pollMaxInterval);
    }

    public function testPollTimeoutIsCappedToAMaximum(): void
    {
        $config = Config::create('k', ['pollTimeout' => PHP_INT_MAX]);
        self::assertSame(Config::MAX_POLL_TIMEOUT, $config->pollTimeout);
    }

    public function testTimeoutIsNeverDisabled(): void
    {
        // A per-request timeout of 0 means "no timeout" in Guzzle — the classic
        // unbounded-hang landmine. It must be floored to at least 1s.
        $config = Config::create('k', ['timeout' => 0]);
        self::assertGreaterThanOrEqual(1, $config->timeout);
    }

    public function testMisconfiguredZeroIntervalIsFlooredNotBusyLooped(): void
    {
        // pollInterval 0 previously produced a usleep(0) busy-loop (negative -> ValueError).
        // Capture the interval actually handed to the sleeper and assert it respects the
        // floor, so this fails if the MIN_POLL_INTERVAL clamp is ever removed.
        $slept = [];
        $client = $this->clientWithSleeper($slept, ['pollInterval' => 0.0]);

        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'incomplete']]));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'completed']]));

        $job = $client->jobs()->wait('j');

        self::assertTrue($job->isCompleted());
        self::assertNotEmpty($slept, 'wait() must have paused at least once between polls.');
        foreach ($slept as $interval) {
            self::assertGreaterThanOrEqual(Config::MIN_POLL_INTERVAL, $interval);
        }
    }

    public function testCanceledJobRaisesConversionFailed(): void
    {
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'canceled']]));

        $this->expectException(ConversionFailedException::class);
        $this->client()->jobs()->wait('j');
    }

    public function testCanceledJobIsTerminalWhenThrowOnFailureDisabled(): void
    {
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'canceled']]));

        $job = $this->client()->jobs()->wait('j', throwOnFailure: false);

        self::assertTrue($job->isCanceled());
        self::assertTrue($job->isTerminal());
    }

    public function testHonoredRetryAfterIsClampedToCeiling(): void
    {
        $slept = [];
        $client = $this->clientWithSleeper($slept, ['maxRetries' => 1]);

        $this->http->addResponse($this->jsonResponse(429, ['message' => 'slow'], ['Retry-After' => '99999']));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'completed']]));

        $client->jobs()->get('j');

        self::assertCount(1, $slept);
        self::assertLessThanOrEqual(120.0, $slept[0]);
        self::assertSame(120.0, $slept[0]);
    }

    public function testRetryAfterHttpDateIsParsedAndClamped(): void
    {
        $slept = [];
        $client = $this->clientWithSleeper($slept, ['maxRetries' => 1]);

        // A date well beyond the ceiling must clamp to the ceiling, not fall back to
        // the short exponential backoff (which would re-hammer a rate-limited endpoint).
        $future = gmdate('D, d M Y H:i:s \G\M\T', time() + 3600);
        $this->http->addResponse($this->jsonResponse(503, ['message' => 'busy'], ['Retry-After' => $future]));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'completed']]));

        $client->jobs()->get('j');

        self::assertCount(1, $slept);
        self::assertSame(120.0, $slept[0]);
    }

    public function testRetryAfterZeroFallsBackToBackoffInsteadOfHammering(): void
    {
        $slept = [];
        $client = $this->clientWithSleeper($slept, ['maxRetries' => 1]);

        // Retry-After: 0 (or a past HTTP-date) must NOT produce a zero-delay immediate
        // retry — it falls through to the (jittered) exponential backoff, which is > 0.
        $this->http->addResponse($this->jsonResponse(503, ['message' => 'busy'], ['Retry-After' => '0']));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'completed']]));

        $client->jobs()->get('j');

        self::assertCount(1, $slept);
        self::assertGreaterThanOrEqual(0.5, $slept[0]);
    }

    public function testNonSeekableBodyIsNotRetried(): void
    {
        // Use 429 (retryable for ANY method) so idempotency is not what blocks the
        // retry — the non-seekable body is the only thing that can. This keeps the
        // test a real guard for isReplayable(): revert that gate and it retries to 200.
        $factory = new HttpFactory();
        $config = Config::create('k', ['maxRetries' => 3]);
        $transport = new Transport($this->http, $factory, $factory, $config, static fn (float $s): null => null);

        $body = new NoSeekStream(Utils::streamFor('binary-upload-payload'));
        $request = $factory->createRequest('POST', 'https://api.api2convert.com/v2/jobs')->withBody($body);

        $this->http->addResponse($this->jsonResponse(429, ['message' => 'slow']));
        $this->http->addResponse($this->jsonResponse(429, ['message' => 'slow']));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'completed']]));

        $response = $transport->send($request);

        self::assertSame(429, $response->getStatusCode());
        self::assertCount(1, $this->http->getRequests(), 'A non-seekable body must be sent exactly once.');
    }

    public function testSeekableNonIdempotentPostIsNotRetriedOnServerError(): void
    {
        // A seekable body is replayable, but a bare POST is not idempotent: retrying a
        // 5xx could create a duplicate job. Replayability alone must not enable retry.
        $factory = new HttpFactory();
        $config = Config::create('k', ['maxRetries' => 2]);
        $transport = new Transport($this->http, $factory, $factory, $config, static fn (float $s): null => null);

        $request = $factory->createRequest('POST', 'https://api.api2convert.com/v2/jobs')
            ->withBody(Utils::streamFor('seekable-payload'));

        $this->http->addResponse($this->jsonResponse(503, ['message' => 'temp']));
        $this->http->addResponse($this->jsonResponse(503, ['message' => 'temp']));

        $response = $transport->send($request);

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $this->http->getRequests(), 'A non-idempotent POST must not be auto-retried on 5xx.');
    }

    public function testPostWithIdempotencyKeyIsRetriedOnServerError(): void
    {
        // An Idempotency-Key makes the POST retry-safe (the backend deduplicates it),
        // so a replayable body IS retried.
        $factory = new HttpFactory();
        $config = Config::create('k', ['maxRetries' => 2]);
        $transport = new Transport($this->http, $factory, $factory, $config, static fn (float $s): null => null);

        $request = $factory->createRequest('POST', 'https://api.api2convert.com/v2/jobs')
            ->withHeader('Idempotency-Key', 'key-123')
            ->withBody(Utils::streamFor('seekable-payload'));

        $this->http->addResponse($this->jsonResponse(503, ['message' => 'temp']));
        $this->http->addResponse($this->jsonResponse(503, ['message' => 'temp']));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'completed']]));

        $response = $transport->send($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $this->http->getRequests());
    }

    public function testRateLimitedPostIsRetriedEvenWithoutIdempotencyKey(): void
    {
        // A 429 is rejected before processing, so it is safe to retry for any method.
        $factory = new HttpFactory();
        $config = Config::create('k', ['maxRetries' => 1]);
        $transport = new Transport($this->http, $factory, $factory, $config, static fn (float $s): null => null);

        $request = $factory->createRequest('POST', 'https://api.api2convert.com/v2/jobs')
            ->withBody(Utils::streamFor('seekable-payload'));

        $this->http->addResponse($this->jsonResponse(429, ['message' => 'slow']));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'j', 'status' => ['code' => 'completed']]));

        $response = $transport->send($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $this->http->getRequests());
    }

    /**
     * @param list<float> $slept  Captures the durations passed to the sleeper.
     * @param array<string, mixed> $options
     */
    private function clientWithSleeper(array &$slept, array $options = []): Api2Convert
    {
        return new Api2Convert(
            apiKey: 'test-key',
            options: $options,
            httpClient: $this->http,
            sleeper: static function (float $seconds) use (&$slept): void {
                $slept[] = $seconds;
            },
        );
    }
}
