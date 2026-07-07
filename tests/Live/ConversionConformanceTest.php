<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Live;

use Api2Convert\Api2Convert;
use Api2Convert\Exception\AuthenticationException;
use Api2Convert\Exception\ConversionFailedException;
use Api2Convert\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Live conformance suite — the canonical, cross-SDK set of scenarios that
 * exercises the real API2Convert API end to end. Every scenario is written to
 * read like a usage example, so this file doubles as an executable tour of the
 * SDK: build a client, convert, discover, drive the job lifecycle, and handle
 * the typed errors.
 *
 * Because these hit the real API and consume quota, the whole suite skips unless
 * `API2CONVERT_API_KEY` is set — so it is safe to keep in the default checkout:
 *
 *   API2CONVERT_API_KEY=<key> vendor/bin/phpunit --testsuite live
 *
 * `API2CONVERT_BASE_URL` overrides the host (e.g. a beta environment). Never
 * commit a real key — it is read only from the environment.
 *
 * The seven scenarios mirror the shared spec implemented by every api2convert
 * SDK (php, python, java, go, nodejs, dotnet, ruby, rust):
 *
 *   1. testConvertRemoteUrlToPng           — one-call convert of a URL
 *   2. testUploadLocalFileAndConvert       — multipart upload of a file
 *   3. testConvertWithOptions              — apply conversion options
 *   4. testDiscoverConversionCatalog       — options/catalog discovery
 *   5. testManualJobLifecycleAndInspection — create → input → start → wait
 *   6. testInvalidTargetIsATypedError      — validation error handling
 *   7. testAuthenticationErrorLeaksNoSecret — auth error, no key leak
 */
final class ConversionConformanceTest extends TestCase
{
    /**
     * A small, stable public image used as a remote input everywhere.
     */
    private const REMOTE_URL =
        'https://example-files.online-convert.com/raster%20image/jpg/example_small.jpg';

    /**
     * A minimal valid 1×1 PNG, written to disk to exercise the real multipart
     * upload handshake (remote-URL inputs skip upload entirely).
     */
    private const ONE_PX_PNG =
        "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A\x00\x00\x00\x0D\x49\x48\x44\x52"
        . "\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90\x77\x53"
        . "\xDE\x00\x00\x00\x0C\x49\x44\x41\x54\x08\xD7\x63\xF8\xCF\xC0\x00"
        . "\x00\x00\x03\x01\x01\x00\x18\xDD\x8D\xB0\x00\x00\x00\x00\x49\x45"
        . "\x4E\x44\xAE\x42\x60\x82";

    /**
     * Build a client from the environment, or skip the test when no key is set.
     *
     * This is the idiomatic construction: pass the key to the constructor (it
     * also falls back to `API2CONVERT_API_KEY`); here we additionally honor
     * `API2CONVERT_BASE_URL` so the same suite can target prod or a beta host.
     */
    private function client(): Api2Convert
    {
        $key = getenv('API2CONVERT_API_KEY');
        if ($key === false || $key === '') {
            self::markTestSkipped('Set API2CONVERT_API_KEY to run live conformance tests.');
        }

        $baseUrl = getenv('API2CONVERT_BASE_URL');

        return new Api2Convert($key, $baseUrl !== false && $baseUrl !== '' ? ['baseUrl' => $baseUrl] : []);
    }

    // 1. One-call convert of a remote URL -----------------------------------
    //
    // The simplest usage: hand `convert()` a URL and a target format. The SDK
    // creates a server-side-fetch job, polls it to completion, and hands back a
    // result you can save straight to disk.
    public function testConvertRemoteUrlToPng(): void
    {
        $result = $this->client()->convert(self::REMOTE_URL, 'png');
        self::assertTrue($result->job->isCompleted(), 'job should complete');

        $target = sys_get_temp_dir() . '/a2c-live-remote-' . uniqid() . '.png';
        $path = $result->save($target);
        self::assertFileExists($path);
        self::assertGreaterThan(0, filesize($path), 'output should be non-empty');
        unlink($path);
    }

    // 2. Upload and convert a local file ------------------------------------
    //
    // For a local path (or resource / stream), the SDK stages the job, streams
    // the file to the per-job upload server (authenticated with the job's
    // `X-Oc-Token`, never your account key), starts it, polls, and downloads.
    public function testUploadLocalFileAndConvert(): void
    {
        $client = $this->client();

        $src = sys_get_temp_dir() . '/a2c-live-pixel-' . uniqid() . '.png';
        file_put_contents($src, self::ONE_PX_PNG);

        try {
            $result = $client->convert($src, 'jpg');
            self::assertTrue($result->job->isCompleted(), 'uploaded job should complete');

            $bytes = $result->contents();
            self::assertNotSame('', $bytes, 'converted output should be non-empty');
            // A JPEG starts with the SOI marker 0xFF 0xD8.
            self::assertSame("\xFF\xD8", substr($bytes, 0, 2), 'output should be a JPEG');
        } finally {
            unlink($src);
        }
    }

    // 3. Apply conversion options -------------------------------------------
    //
    // Pass target-specific options as the third argument to `convert()`. Discover
    // the valid keys for a target with `$client->options()` (see the next
    // scenario); here we re-encode at a lower JPEG quality.
    public function testConvertWithOptions(): void
    {
        $result = $this->client()->convert(
            self::REMOTE_URL,
            'jpg',
            // Add e.g. 'width' => 64, 'height' => 64 to resize.
            ['quality' => 50],
        );
        self::assertTrue($result->job->isCompleted(), 'job should complete');

        $bytes = $result->contents();
        self::assertNotSame('', $bytes, 'converted output should be non-empty');
    }

    // 4. Discover the conversion catalog ------------------------------------
    //
    // `conversions()->list()` and `options()` describe what the API can do —
    // which targets exist and which options each accepts. Neither consumes
    // conversion quota, so they are cheap to call before building a request.
    public function testDiscoverConversionCatalog(): void
    {
        $client = $this->client();

        // Which conversions target `jpg`?
        $conversions = $client->conversions()->list(null, 'jpg');
        self::assertNotEmpty($conversions, 'the catalog should list at least one conversion to jpg');

        // The option schema for a target (type / enum / default / range per
        // option). We only assert the call succeeds (returns without throwing).
        $client->options('png', 'image');
    }

    // 5. Drive the full job lifecycle by hand -------------------------------
    //
    // `convert()` is built from these primitives. Driving them yourself unlocks
    // compound/merge jobs, custom inputs, and step-by-step inspection: create a
    // staged job, attach an input, start it, wait for completion, then inspect
    // the job's status and output metadata.
    public function testManualJobLifecycleAndInspection(): void
    {
        $jobs = $this->client()->jobs();

        // Stage a job (process: false) so we can attach inputs before starting.
        $job = $jobs->create(['process' => false, 'conversion' => [['target' => 'png']]]);
        self::assertNotSame('', $job->id, 'a created job has an id');

        // Attach a remote input, then start processing.
        $jobs->addInput($job->id, ['type' => 'remote', 'source' => self::REMOTE_URL]);
        $jobs->start($job->id);

        // Poll to a terminal status.
        $finished = $jobs->wait($job->id);
        self::assertTrue($finished->isCompleted(), 'job should complete');

        // Inspect the outputs — both from the finished job and via the outputs API.
        self::assertNotEmpty($finished->output, 'job should have an output');
        $outputs = $jobs->outputs($job->id);
        self::assertCount(
            count($finished->output),
            $outputs,
            'outputs() should match the job\'s output list',
        );
        self::assertNotSame('', $finished->output[0]->uri, 'output has a download URI');
    }

    // 6. Validation error on an unknown target ------------------------------
    //
    // The real API rejects an unknown target synchronously at job creation
    // (HTTP 400 -> ValidationException), not as an async failed job. Some hosts
    // instead surface it as a failed job (ConversionFailedException); accept
    // either typed failure.
    public function testInvalidTargetIsATypedError(): void
    {
        try {
            $this->client()->convert(self::REMOTE_URL, 'this-is-not-a-real-target');
            self::fail('an unknown target should raise a typed error');
        } catch (ValidationException | ConversionFailedException $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    // 7. Authentication error, with no secret leak --------------------------
    //
    // A bad key produces a typed `AuthenticationException` carrying the HTTP
    // status. Crucially, the SDK never puts a credential into an error message —
    // we assert the bogus key does not appear in the rendered error.
    public function testAuthenticationErrorLeaksNoSecret(): void
    {
        // Gate on a real key like the rest of the suite (so this only runs when
        // the API is reachable), then build a SECOND client with a bogus key.
        $this->client();

        $bogusKey = 'a2c-invalid-key-for-testing';
        $baseUrl = getenv('API2CONVERT_BASE_URL');
        $client = new Api2Convert(
            $bogusKey,
            $baseUrl !== false && $baseUrl !== '' ? ['baseUrl' => $baseUrl] : [],
        );

        try {
            $client->jobs()->list();
            self::fail('a bad key must not authenticate');
        } catch (AuthenticationException $e) {
            self::assertContains(
                $e->statusCode,
                [401, 403],
                'expected HTTP 401/403, got ' . $e->statusCode,
            );
            self::assertStringNotContainsString(
                $bogusKey,
                $e->getMessage(),
                'the error message must not leak the API key',
            );
        }
    }
}
