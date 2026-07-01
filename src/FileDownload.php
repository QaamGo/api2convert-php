<?php

declare(strict_types=1);

namespace Api2Convert;

use Api2Convert\Exception\Api2ConvertException;
use Api2Convert\Http\Transport;
use Api2Convert\Model\OutputFile;

/**
 * A downloadable output file. Returned by `$client->download($output)` and used
 * internally by {@see ConversionResult}.
 */
final class FileDownload
{
    public function __construct(
        private readonly Transport $transport,
        private readonly OutputFile $output,
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
     * @param string|null $downloadPassword Required only if the job set a download password.
     * @return string The path the file was written to.
     */
    public function save(string $pathOrDir, ?string $downloadPassword = null): string
    {
        $target = $this->resolveTarget($pathOrDir);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new Api2ConvertException("Could not create directory: {$dir}");
        }

        $source = $this->transport->download($this->output->uri, $this->headers($downloadPassword));
        $handle = fopen($target, 'w');
        if ($handle === false) {
            throw new Api2ConvertException("Could not open file for writing: {$target}");
        }

        try {
            while (!$source->eof()) {
                fwrite($handle, $source->read(1 << 16));
            }
        } finally {
            fclose($handle);
            $source->close();
        }

        return $target;
    }

    /**
     * Download the file and return its contents (loads into memory).
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
            $name = $this->output->filename ?? ($this->output->id ?? 'output');

            return rtrim($pathOrDir, '/\\') . DIRECTORY_SEPARATOR . $name;
        }

        return $pathOrDir;
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $downloadPassword): array
    {
        return $downloadPassword !== null ? ['X-Oc-Download-Password' => $downloadPassword] : [];
    }
}
