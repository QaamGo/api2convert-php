<?php

declare(strict_types=1);

namespace Api2Convert;

use Api2Convert\Exception\Api2ConvertException;
use Api2Convert\Exception\NetworkException;
use Api2Convert\Http\Transport;
use Api2Convert\Model\OutputFile;
use Psr\Http\Message\StreamInterface;

/**
 * A downloadable output file. Returned by `$client->download($output)` and used
 * internally by {@see ConversionResult}.
 */
final class FileDownload
{
    /**
     * @param string|null $downloadPassword Password remembered from `convert()` / `$client->download()`,
     *                                       sent automatically on download; overridable per call.
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly OutputFile $output,
        private readonly ?string $downloadPassword = null,
    ) {
    }

    /**
     * The self-contained download URL (no auth required).
     */
    public function url(): string
    {
        return $this->output->uri;
    }

    /**
     * Stream the file to disk.
     *
     * @param string      $pathOrDir        A file path, or a directory (the API filename is used).
     * @param string|null $downloadPassword Only needed if the job set a download password and it
     *                                       wasn't already supplied at conversion time; overrides
     *                                       the remembered one.
     * @return string The path the file was written to.
     */
    public function save(string $pathOrDir, ?string $downloadPassword = null): string
    {
        $target = $this->resolveTarget($pathOrDir);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new Api2ConvertException("Could not create directory: {$dir}");
        }

        // Stream into a sibling temp file and rename over the target only after a clean
        // write+close, so a download either fully replaces the target or leaves it
        // untouched. Streaming straight into the target used to truncate a pre-existing
        // file up front and, on a mid-stream failure, delete it — destroying a complete
        // file when a retried/partial download failed.
        $temp = @tempnam($dir, '.a2c-download-');
        if ($temp === false) {
            throw new Api2ConvertException("Could not write file: {$target}");
        }

        $committed = false;
        try {
            $source = $this->transport->download($this->output->uri, $this->headers($downloadPassword));
            $handle = fopen($temp, 'w');
            if ($handle === false) {
                $source->close();
                throw new Api2ConvertException("Could not open file for writing: {$target}");
            }

            $closed = false;
            try {
                $this->copy($source, $handle, $target);
                // fclose flushes buffered bytes; a failure here (e.g. a disk filling up,
                // surfacing only on flush) must not let a truncated write pass as a
                // complete file — surface it as a write error.
                $closed = true;
                if (fclose($handle) === false) {
                    throw new Api2ConvertException("Could not write file: {$target}");
                }
            } finally {
                $source->close();
                if (!$closed) {
                    fclose($handle);
                }
            }

            if (!@rename($temp, $target)) {
                throw new Api2ConvertException("Could not write file: {$target}");
            }
            $committed = true;
        } finally {
            // On any failure the target is never touched; only the temp file is removed.
            if (!$committed && is_file($temp)) {
                @unlink($temp);
            }
        }

        return $target;
    }

    /**
     * Copy the download body into $handle, attributing a mid-stream failure to the
     * correct side: a read fault on the network response surfaces as a typed
     * {@see NetworkException}, while a write fault is a filesystem
     * {@see Api2ConvertException} — so a dropped connection is never mislabelled as a
     * "could not write" error, and a full disk is never mislabelled as a network error.
     *
     * @param resource $handle
     */
    private function copy(StreamInterface $source, $handle, string $target): void
    {
        while (!$source->eof()) {
            try {
                $chunk = $source->read(1 << 16);
            } catch (\Throwable $e) {
                throw new NetworkException('The download was interrupted: ' . $e->getMessage(), 0, $e);
            }

            if (fwrite($handle, $chunk) === false) {
                throw new Api2ConvertException("Could not write file: {$target}");
            }
        }
    }

    /**
     * Download the file and return its contents (loads into memory).
     *
     * @param string|null $downloadPassword Overrides the password remembered from conversion time.
     */
    public function contents(?string $downloadPassword = null): string
    {
        return (string) $this->transport->download($this->output->uri, $this->headers($downloadPassword));
    }

    private function resolveTarget(string $pathOrDir): string
    {
        $looksLikeDir = is_dir($pathOrDir)
            || str_ends_with($pathOrDir, '/')
            || str_ends_with($pathOrDir, DIRECTORY_SEPARATOR);

        if ($looksLikeDir) {
            $name = $this->safeName($this->output->filename)
                ?? $this->safeName($this->output->id)
                ?? 'output';

            return rtrim($pathOrDir, '/\\') . DIRECTORY_SEPARATOR . $name;
        }

        return $pathOrDir;
    }

    /**
     * Reduce an API-supplied name to a bare filename safe to append to a target
     * directory. `output.filename` / `output.id` come straight from the API JSON,
     * so a value like `../../etc/cron.d/evil` (or one containing separators or a
     * NUL byte) must never escape the directory the caller chose. Returns null when
     * nothing usable remains, so the caller can fall back.
     */
    private function safeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        // Normalize Windows separators and drop NUL so basename() strips every path
        // component and any leading `../` on all platforms; trim surrounding space.
        $base = trim(basename(str_replace(["\0", '\\'], ['', '/'], $name)));

        if ($base === '' || $base === '.' || $base === '..') {
            return null;
        }

        return $base;
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $downloadPassword): array
    {
        $password = $downloadPassword ?? $this->downloadPassword;

        return $password !== null ? ['X-Api2convert-Download-Password' => $password] : [];
    }
}
