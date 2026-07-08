<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Live;

use Api2Convert\Api2Convert;
use Api2Convert\Exception\AuthenticationException;
use Api2Convert\Exception\ConversionFailedException;
use Api2Convert\Exception\ValidationException;
use Api2Convert\Model\Job;
use Api2Convert\Model\Preset;
use PHPUnit\Framework\TestCase;

/**
 * Live conformance suite — the canonical, cross-SDK set of scenarios that
 * exercises the real API2Convert API end to end. It mirrors, one-to-one, the 20
 * documented examples in {@see /examples} (the same operations the api2convert.com
 * guides show), plus two negative tests. Each test performs the same call as the
 * matching example file and asserts the outcome, so this file doubles as an
 * executable, verified tour of the SDK.
 *
 * Because these hit the real API and consume quota, the whole suite skips unless
 * `API2CONVERT_API_KEY` is set — so it is safe to keep in the default checkout:
 *
 *   API2CONVERT_API_KEY=<key> vendor/bin/phpunit --testsuite live
 *
 * `API2CONVERT_BASE_URL` overrides the host (e.g. a beta environment). Never
 * commit a real key — it is read only from the environment.
 *
 * Catalog (each maps to examples/<name>.php):
 *   1. quickstart          9.  create-hashes      17. presets
 *   2. convert-files       10. extract-assets     18. statistics
 *   3. uploading-files     11. file-analysis      19. rate-limits
 *   4. job-lifecycle       12. compare-files      20. authentication
 *   5. add-watermark       13. capture-website    + invalid-target (validation)
 *   6. create-thumbnails   14. audio-operations   + bad-key (auth, no leak)
 *   7. compress-files      15. image-operations
 *   8. create-archives     16. webhooks
 */
final class ConversionConformanceTest extends TestCase
{
    // Public example fixtures (example-files.online-convert.com).
    private const PDF = 'https://example-files.online-convert.com/document/pdf/example.pdf';
    private const PNG = 'https://example-files.online-convert.com/raster%20image/png/example.png';
    private const JPG = 'https://example-files.online-convert.com/raster%20image/jpg/example.jpg';
    private const JPG_SMALL = 'https://example-files.online-convert.com/raster%20image/jpg/example_small.jpg';
    private const WAV = 'https://example-files.online-convert.com/audio/wav/example.wav';
    private const DOCX = 'https://example-files.online-convert.com/document/docx/example.docx';
    private const ZIP = 'https://example-files.online-convert.com/archive/zip/example.zip';

    /**
     * A minimal valid 1x1 PNG, written to disk to exercise the real multipart
     * upload handshake (remote-URL inputs skip upload entirely).
     */
    private const ONE_PX_PNG =
        "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A\x00\x00\x00\x0D\x49\x48\x44\x52"
        . "\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90\x77\x53"
        . "\xDE\x00\x00\x00\x0C\x49\x44\x41\x54\x08\xD7\x63\xF8\xCF\xC0\x00"
        . "\x00\x00\x03\x01\x01\x00\x18\xDD\x8D\xB0\x00\x00\x00\x00\x49\x45"
        . "\x4E\x44\xAE\x42\x60\x82";

    /**
     * Build a client from the environment, or skip when no key is set. The key is
     * passed to the constructor (which also falls back to `API2CONVERT_API_KEY`);
     * we additionally honor `API2CONVERT_BASE_URL` so the suite can target a beta.
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

    // 1. quickstart — one-call convert of a remote URL, look it up, download it.
    public function testQuickstart(): void
    {
        $client = $this->client();
        $result = $client->convert(self::JPG, 'png');
        self::assertTrue($result->job->isCompleted(), 'job should complete');

        $job = $client->jobs()->get($result->job->id);
        self::assertSame($result->job->id, $job->id);

        $target = sys_get_temp_dir() . '/a2c-quickstart-' . uniqid() . '.png';
        $path = $result->save($target);
        self::assertFileExists($path);
        self::assertGreaterThan(0, filesize($path), 'output should be non-empty');
        unlink($path);
    }

    // 2. convert-files — browse the catalog (all + filtered), then convert.
    public function testConvertFiles(): void
    {
        $client = $this->client();

        $all = $client->conversions()->list();
        self::assertNotEmpty($all, 'the catalog should be non-empty');

        $toPng = $client->conversions()->list(target: 'png');
        self::assertNotEmpty($toPng, 'there should be at least one conversion to png');

        $result = $client->convert(self::JPG, 'png');
        self::assertTrue($result->job->isCompleted(), 'job should complete');
    }

    // 3. uploading-files — multipart upload + convert of a local file.
    public function testUploadingFiles(): void
    {
        $client = $this->client();

        $src = sys_get_temp_dir() . '/a2c-upload-' . uniqid() . '.png';
        file_put_contents($src, self::ONE_PX_PNG);

        try {
            $result = $client->convert($src, 'png');
            self::assertTrue($result->job->isCompleted(), 'uploaded job should complete');
            self::assertNotSame('', $result->contents(), 'output should be non-empty');
        } finally {
            unlink($src);
        }
    }

    // 4. job-lifecycle — create (staged) → add input → start → wait → outputs.
    public function testJobLifecycle(): void
    {
        $jobs = $this->client()->jobs();

        $job = $jobs->create([
            'process' => false,
            'conversion' => [['category' => 'image', 'target' => 'png']],
        ]);
        self::assertNotSame('', $job->id, 'a created job has an id');

        $jobs->addInput($job->id, ['type' => 'remote', 'source' => self::JPG]);
        $jobs->start($job->id);

        $finished = $jobs->wait($job->id);
        self::assertTrue($finished->isCompleted(), 'job should complete');

        $outputs = $jobs->outputs($job->id);
        self::assertNotEmpty($outputs, 'job should have outputs');
        self::assertNotSame('', $outputs[0]->uri, 'output has a download URI');
    }

    // 5. add-watermark — stamp a PNG onto a PDF (two inputs).
    public function testAddWatermark(): void
    {
        $jobs = $this->client()->jobs();

        $job = $jobs->create([
            'process' => true,
            'input' => [
                ['type' => 'remote', 'source' => self::PDF],
                ['type' => 'remote', 'source' => self::PNG],
            ],
            'conversion' => [
                [
                    'category' => 'document',
                    'target' => 'pdf',
                    'options' => ['stamp' => true, 'alignment' => 'center'],
                ],
            ],
        ]);

        $finished = $jobs->wait($job->id);
        self::assertTrue($finished->isCompleted(), 'job should complete');
        self::assertNotEmpty($finished->output, 'job should have an output');
    }

    // 6. create-thumbnails — first page of a PDF as a 300px PNG thumbnail.
    public function testCreateThumbnails(): void
    {
        $result = $this->client()->convert(
            self::PDF,
            'thumbnail',
            ['thumbnail_target' => 'png', 'width' => 300, 'pages' => 'first', 'dpi' => 150],
            'operation',
        );
        self::assertTrue($result->job->isCompleted(), 'job should complete');
        self::assertNotEmpty($result->outputs(), 'job should have an output');
    }

    // 7. compress-files — high-compression pass over a JPG.
    public function testCompressFiles(): void
    {
        $result = $this->client()->convert(
            self::JPG,
            'compress',
            ['compression_level' => 'high'],
            'operation',
        );
        self::assertTrue($result->job->isCompleted(), 'job should complete');
        self::assertNotEmpty($result->outputs(), 'job should have an output');
    }

    // 8. create-archives — bundle two remote files into a ZIP.
    public function testCreateArchives(): void
    {
        $jobs = $this->client()->jobs();

        $job = $jobs->create([
            'process' => true,
            'input' => [
                ['type' => 'remote', 'source' => self::PDF],
                ['type' => 'remote', 'source' => self::PNG],
            ],
            'conversion' => [['category' => 'archive', 'target' => 'zip']],
        ]);

        $finished = $jobs->wait($job->id);
        self::assertTrue($finished->isCompleted(), 'job should complete');
        self::assertNotEmpty($finished->output, 'job should have an output');
    }

    // 9. create-hashes — SHA-256 of a remote ZIP.
    public function testCreateHashes(): void
    {
        $result = $this->client()->convert(self::ZIP, 'sha256', [], 'hash');
        self::assertTrue($result->job->isCompleted(), 'job should complete');
        self::assertNotSame('', trim($result->contents()), 'the hash output should be non-empty');
    }

    // 10. extract-assets — pull embedded assets out of a DOCX.
    public function testExtractAssets(): void
    {
        $result = $this->client()->convert(self::DOCX, 'extract-assets', [], 'operation');
        self::assertTrue($result->job->isCompleted(), 'job should complete');
        self::assertNotEmpty($result->outputs(), 'job should have at least one output');
    }

    // 11. file-analysis — a JPG's metadata as JSON.
    public function testFileAnalysis(): void
    {
        $result = $this->client()->convert(self::JPG, 'json', [], 'metadata');
        self::assertTrue($result->job->isCompleted(), 'job should complete');
        self::assertNotSame('', $result->contents(), 'the analysis output should be non-empty');
    }

    // 12. compare-files — SSIM diff of two images.
    public function testCompareFiles(): void
    {
        $jobs = $this->client()->jobs();

        $job = $jobs->create([
            'process' => true,
            'input' => [
                ['type' => 'remote', 'source' => self::JPG_SMALL],
                ['type' => 'remote', 'source' => self::JPG],
            ],
            'conversion' => [
                [
                    'category' => 'operation',
                    'target' => 'compare-image',
                    'options' => ['method' => 'ssim', 'threshold' => 5, 'diff_color' => 'red'],
                ],
            ],
        ]);

        $finished = $jobs->wait($job->id);
        self::assertTrue($finished->isCompleted(), 'job should complete');
    }

    // 13. capture-website — screenshot a page and deliver a PNG.
    public function testCaptureWebsite(): void
    {
        $jobs = $this->client()->jobs();

        $job = $jobs->create([
            'process' => true,
            'input' => [
                [
                    'type' => 'remote',
                    'source' => 'https://www.online-convert.com',
                    'engine' => 'screenshot',
                    'options' => [
                        'screen_width' => 1280,
                        'screen_height' => 1024,
                        'device_scale_factor' => 1,
                    ],
                ],
            ],
            'conversion' => [['category' => 'image', 'target' => 'png']],
        ]);

        $finished = $jobs->wait($job->id);
        self::assertTrue($finished->isCompleted(), 'job should complete');
        self::assertNotEmpty($finished->output, 'job should have an output');
    }

    // 14. audio-operations — WAV to stereo 192 kbps AAC.
    public function testAudioOperations(): void
    {
        $result = $this->client()->convert(
            self::WAV,
            'aac',
            ['audio_codec' => 'aac', 'audio_bitrate' => 192, 'channels' => 'stereo', 'frequency' => 44100],
            'audio',
        );
        self::assertTrue($result->job->isCompleted(), 'job should complete');
        self::assertNotSame('', $result->contents(), 'output should be non-empty');
    }

    // 15. image-operations — resize a JPG to 800x600, crop to aspect ratio.
    public function testImageOperations(): void
    {
        $result = $this->client()->convert(
            self::JPG,
            'resize-image',
            ['width' => 800, 'height' => 600, 'resize_by' => 'px', 'resize_handling' => 'keep_aspect_ratio_crop'],
            'operation',
        );
        self::assertTrue($result->job->isCompleted(), 'job should complete');
        self::assertNotEmpty($result->outputs(), 'job should have an output');
    }

    // 16. webhooks — start an async job with a callback (do not wait for delivery).
    public function testWebhooks(): void
    {
        $job = $this->client()->convertAsync(
            self::DOCX,
            'pdf',
            callback: 'https://your-app.example.com/api2convert/webhook',
            category: 'document',
        );

        self::assertInstanceOf(Job::class, $job);
        self::assertNotSame('', $job->id, 'an async job has an id');
        self::assertFalse($job->isFailed(), 'the started job should not already be failed');
    }

    // 17. presets — list presets for a category/target (may be empty).
    public function testPresets(): void
    {
        $presets = $this->client()->presets()->list(category: 'video', target: 'mp4');
        // The list may legitimately be empty; assert only that the call returned
        // typed presets (and, implicitly, that it did not throw).
        self::assertContainsOnlyInstancesOf(Preset::class, $presets);
    }

    // 18. statistics — usage for a recent month.
    public function testStatistics(): void
    {
        $stats = $this->client()->stats()->month('2026-06');
        // Free-form response; assert it came back and is JSON-serializable (i.e.
        // the call succeeded and returned a decoded body).
        self::assertNotFalse(json_encode($stats), 'stats()->month() should return a decoded response');
    }

    // 19. rate-limits — the account's contracts.
    public function testRateLimits(): void
    {
        $contracts = $this->client()->contracts()->get();
        self::assertNotFalse(json_encode($contracts), 'contracts()->get() should return a decoded response');
    }

    // 20. authentication — the key works: list this key's jobs.
    public function testAuthentication(): void
    {
        $jobs = $this->client()->jobs()->list();
        // Listing the key's jobs proves the key authenticates; the list may be
        // empty, so assert only that it returned typed jobs.
        self::assertContainsOnlyInstancesOf(Job::class, $jobs);
    }

    // Negative: an unknown target is a typed error --------------------------
    //
    // The real API rejects an unknown target synchronously at job creation
    // (HTTP 400 -> ValidationException); some hosts instead surface it as a
    // failed job (ConversionFailedException). Accept either typed failure.
    public function testInvalidTargetIsATypedError(): void
    {
        try {
            $this->client()->convert(self::JPG, 'this-is-not-a-real-target');
            self::fail('an unknown target should raise a typed error');
        } catch (ValidationException | ConversionFailedException $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    // Negative: a bad key is a typed auth error, with no secret leak ---------
    public function testAuthenticationErrorLeaksNoSecret(): void
    {
        // Gate on a real key like the rest of the suite, then build a SECOND
        // client with a bogus key.
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
