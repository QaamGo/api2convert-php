<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Exception\Api2ConvertException;
use Api2Convert\Model\Job;
use Api2Convert\Tests\TestCase;

final class FileUploaderTest extends TestCase
{
    public function testUploadFailsWhenJobHasNoUploadServerOrToken(): void
    {
        // A job created with process=true (or already started) exposes no upload
        // server/token; uploading to it must fail fast, before touching the network.
        $job = Job::fromArray(['id' => 'j', 'status' => ['code' => 'incomplete']]);

        $this->expectException(Api2ConvertException::class);
        $this->expectExceptionMessage('no upload server/token');
        $this->client()->jobs()->upload($job, '/tmp/whatever');
    }

    public function testUploadFailsWhenLocalFileDoesNotExist(): void
    {
        $job = Job::fromArray([
            'id' => 'j',
            'server' => 'https://upload.example.com',
            'token' => 'tok',
            'status' => ['code' => 'incomplete'],
        ]);

        $this->expectException(Api2ConvertException::class);
        $this->expectExceptionMessage('Input file not found');
        $this->client()->jobs()->upload($job, '/no/such/file-6f1c2b.bin');
    }
}
