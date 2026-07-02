<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Model\OutputFile;
use Api2Convert\Tests\TestCase;
use GuzzleHttp\Psr7\Response;

final class FileDownloadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'a2c-download-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    public function testSavingToDirectoryUsesTheApiFilename(): void
    {
        $this->http->addResponse(new Response(200, [], 'PDF-BYTES'));
        $output = new OutputFile(id: 'o', uri: 'https://dl.example.com/x', filename: 'result.pdf');

        $path = $this->client()->download($output)->save($this->dir . '/');

        self::assertSame($this->dir . DIRECTORY_SEPARATOR . 'result.pdf', $path);
        self::assertStringEqualsFile($path, 'PDF-BYTES');
    }

    public function testTraversalFilenameCannotEscapeTheTargetDirectory(): void
    {
        $this->http->addResponse(new Response(200, [], 'DATA'));
        $output = new OutputFile(id: 'o', uri: 'https://dl.example.com/x', filename: '../../evil.txt');

        $path = $this->client()->download($output)->save($this->dir . '/');

        // The API-supplied name is reduced to a bare basename and stays inside the dir.
        self::assertSame($this->dir . DIRECTORY_SEPARATOR . 'evil.txt', $path);
        self::assertFileExists($this->dir . DIRECTORY_SEPARATOR . 'evil.txt');
        self::assertFileDoesNotExist(dirname($this->dir, 2) . DIRECTORY_SEPARATOR . 'evil.txt');
    }

    public function testFallsBackToOutputWhenFilenameIsDotOnly(): void
    {
        $this->http->addResponse(new Response(200, [], 'DATA'));
        $output = new OutputFile(id: null, uri: 'https://dl.example.com/x', filename: '..');

        $path = $this->client()->download($output)->save($this->dir . '/');

        self::assertSame($this->dir . DIRECTORY_SEPARATOR . 'output', $path);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
