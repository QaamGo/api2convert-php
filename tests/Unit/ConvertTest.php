<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Tests\TestCase;

final class ConvertTest extends TestCase
{
    public function testConvertRemoteUrlCreatesStartedJobPollsAndDownloads(): void
    {
        $this->http->addResponse($this->jsonResponse(201, [
            'id' => 'job-1',
            'status' => ['code' => 'incomplete', 'info' => 'Queued'],
        ]));
        $this->http->addResponse($this->jsonResponse(200, [
            'id' => 'job-1',
            'status' => ['code' => 'completed'],
            'output' => [[
                'id' => 'out-1',
                'uri' => 'https://dl.example.com/result.png',
                'filename' => 'result.png',
                'content_type' => 'image/png',
            ]],
        ]));
        $this->http->addResponse(new \GuzzleHttp\Psr7\Response(200, [], 'PNGDATA'));

        $result = $this->client()->convert('https://example.com/photo.jpg', 'png');

        // 1) create job, started immediately, with the remote input inline
        $create = $this->requestAt(0);
        self::assertSame('POST', $create->getMethod());
        self::assertStringEndsWith('/jobs', (string) $create->getUri());
        self::assertSame('test-key', $create->getHeaderLine('X-Oc-Api-Key'));
        $body = $this->bodyOf($create);
        self::assertTrue($body['process']);
        self::assertSame('png', $body['conversion'][0]['target']);
        self::assertSame('remote', $body['input'][0]['type']);
        self::assertSame('https://example.com/photo.jpg', $body['input'][0]['source']);

        // 2) poll
        self::assertSame('GET', $this->requestAt(1)->getMethod());
        self::assertStringEndsWith('/jobs/job-1', (string) $this->requestAt(1)->getUri());

        // 3) save() downloads the output to disk
        self::assertSame('https://dl.example.com/result.png', $result->url());
        $target = sys_get_temp_dir() . '/a2c-' . uniqid() . '.png';
        $written = $result->save($target);
        self::assertSame($target, $written);
        self::assertSame('PNGDATA', file_get_contents($target));
        self::assertSame('https://dl.example.com/result.png', (string) $this->requestAt(2)->getUri());
        unlink($target);
    }

    public function testConvertLocalFileStagesUploadsThenStarts(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'a2c-src');
        self::assertIsString($source);
        file_put_contents($source, 'hello world');

        $this->http->addResponse($this->jsonResponse(201, [
            'id' => 'job-9',
            'token' => 'tok-abc',
            'server' => 'https://www2.api2convert.com/v2',
            'status' => ['code' => 'incomplete'],
        ]));
        $this->http->addResponse($this->jsonResponse(200, [
            'id' => 'in-1',
            'type' => 'upload',
            'filename' => basename($source),
            'status' => 'downloaded',
        ]));
        $this->http->addResponse($this->jsonResponse(200, [
            'id' => 'job-9',
            'status' => ['code' => 'processing'],
        ]));
        $this->http->addResponse($this->jsonResponse(200, [
            'id' => 'job-9',
            'status' => ['code' => 'completed'],
            'output' => [['id' => 'o', 'uri' => 'https://dl.example.com/out.pdf', 'filename' => 'out.pdf']],
        ]));

        $result = $this->client()->convert($source, 'pdf');

        // 1) staged create
        self::assertFalse($this->bodyOf($this->requestAt(0))['process']);

        // 2) upload to the per-job server, authenticated with the job token (NOT the api key)
        $upload = $this->requestAt(1);
        self::assertSame('POST', $upload->getMethod());
        self::assertSame('https://www2.api2convert.com/v2/upload-file/job-9', (string) $upload->getUri());
        self::assertSame('tok-abc', $upload->getHeaderLine('X-Oc-Token'));
        self::assertSame('', $upload->getHeaderLine('X-Oc-Api-Key'));
        self::assertStringContainsString('multipart/form-data', $upload->getHeaderLine('Content-Type'));
        self::assertStringContainsString('name="file"', (string) $upload->getBody());

        // 3) start
        $start = $this->requestAt(2);
        self::assertSame('PATCH', $start->getMethod());
        self::assertTrue($this->bodyOf($start)['process']);

        self::assertSame('out.pdf', $result->output()->filename);
        unlink($source);
    }

    public function testConvertAsyncReturnsImmediatelyWithCallback(): void
    {
        $this->http->addResponse($this->jsonResponse(201, [
            'id' => 'job-async',
            'status' => ['code' => 'incomplete'],
        ]));

        $job = $this->client()->convertAsync(
            'https://example.com/a.mov',
            'mp4',
            callback: 'https://app.example.com/hook',
        );

        self::assertSame('job-async', $job->id);
        self::assertCount(1, $this->http->getRequests());
        $body = $this->bodyOf($this->requestAt(0));
        self::assertSame('https://app.example.com/hook', $body['callback']);
        self::assertTrue($body['notify_status']);
    }

    public function testConvertForwardsOptionsAsConversionOptions(): void
    {
        $this->http->addResponse($this->jsonResponse(201, ['id' => 'j', 'status' => ['code' => 'incomplete']]));
        $this->http->addResponse($this->jsonResponse(200, [
            'id' => 'j',
            'status' => ['code' => 'completed'],
            'output' => [['id' => 'o', 'uri' => 'https://dl.example.com/out.jpg', 'filename' => 'out.jpg']],
        ]));

        $this->client()->convert('https://example.com/photo.png', 'jpg', ['quality' => 85, 'width' => 1280]);

        $conversion = $this->bodyOf($this->requestAt(0))['conversion'][0];
        self::assertSame('jpg', $conversion['target']);
        self::assertSame(['quality' => 85, 'width' => 1280], $conversion['options']);
    }

    public function testContentsDownloadsBody(): void
    {
        $this->http->addResponse($this->jsonResponse(201, ['id' => 'j', 'status' => ['code' => 'incomplete']]));
        $this->http->addResponse($this->jsonResponse(200, [
            'id' => 'j',
            'status' => ['code' => 'completed'],
            'output' => [['id' => 'o', 'uri' => 'https://dl.example.com/out.png']],
        ]));
        $this->http->addResponse(new \GuzzleHttp\Psr7\Response(200, [], 'RAWBYTES'));

        $result = $this->client()->convert('https://example.com/photo.jpg', 'png');

        self::assertSame('RAWBYTES', $result->contents());
        self::assertSame('https://dl.example.com/out.png', (string) $this->requestAt(2)->getUri());
    }

    public function testOptionsDiscoveryQueriesByTargetOnly(): void
    {
        $this->http->addResponse($this->jsonResponse(200, [
            [
                'id' => 'image-to-jpg',
                'category' => 'image',
                'target' => 'jpg',
                'options' => ['quality' => ['type' => 'integer']],
            ],
        ]));

        $options = $this->client()->options('jpg');

        self::assertArrayHasKey('quality', $options);
        $uri = (string) $this->requestAt(0)->getUri();
        self::assertStringContainsString('target=jpg', $uri);
        self::assertStringNotContainsString('category=', $uri);
    }
}
