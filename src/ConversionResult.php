<?php

declare(strict_types=1);

namespace Api2Convert;

use Api2Convert\Exception\Api2ConvertException;
use Api2Convert\Http\Transport;
use Api2Convert\Model\Job;
use Api2Convert\Model\OutputFile;

/**
 * The result of a completed conversion.
 *
 * The common case is one output: `$result->save('out.pdf')`. Jobs that produce
 * several files expose them via {@see outputs()} and {@see download()}.
 */
final class ConversionResult
{
    public function __construct(
        public readonly Job $job,
        private readonly Transport $transport,
        private readonly int $index = 0,
    ) {
    }

    /**
     * The selected output file (the first one by default).
     */
    public function output(): OutputFile
    {
        return $this->job->output[$this->index]
            ?? throw new Api2ConvertException('The job produced no output files.');
    }

    /**
     * All output files produced by the job.
     *
     * @return list<OutputFile>
     */
    public function outputs(): array
    {
        return $this->job->output;
    }

    /**
     * The download URL of the selected output (self-contained, no auth).
     */
    public function url(): string
    {
        return $this->output()->uri;
    }

    /**
     * Download the selected output to disk.
     *
     * @return string The path the file was written to.
     */
    public function save(string $pathOrDir, ?string $downloadPassword = null): string
    {
        return $this->download()->save($pathOrDir, $downloadPassword);
    }

    /**
     * Download the selected output and return its contents (loads into memory).
     */
    public function contents(?string $downloadPassword = null): string
    {
        return $this->download()->contents($downloadPassword);
    }

    /**
     * A {@see FileDownload} for a specific output (defaults to the selected one).
     */
    public function download(?OutputFile $output = null): FileDownload
    {
        return new FileDownload($this->transport, $output ?? $this->output());
    }
}
