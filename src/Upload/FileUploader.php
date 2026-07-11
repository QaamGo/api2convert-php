<?php

declare(strict_types=1);

namespace Api2Convert\Upload;

use Api2Convert\Exception\Api2ConvertException;
use Api2Convert\Http\Transport;
use Api2Convert\Model\InputFile;
use Api2Convert\Model\Job;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Uploads a local file to a job's per-job upload server.
 *
 * This step is intentionally hand-written: it is NOT described by the OpenAPI
 * spec. It posts a `multipart/form-data` body (field `file`) to
 * `{job.server}/upload-file/{job.id}` and authenticates with the per-job
 * `x-api2convert-token` header — never the account API key. The body is streamed, so
 * large files do not have to be read into memory.
 *
 * @internal
 */
final class FileUploader
{
    public function __construct(
        private readonly Transport $transport,
        private readonly RequestFactoryInterface $requestFactory,
    ) {
    }

    /**
     * @param string|resource|StreamInterface $file     A path, an open stream/resource, or a PSR-7 stream.
     * @param string|null                     $filename Name advertised to the API (defaults to the path's basename).
     */
    public function upload(Job $job, mixed $file, ?string $filename = null): InputFile
    {
        if ($job->server === null || $job->server === '' || $job->token === null) {
            throw new Api2ConvertException(
                'Cannot upload: the job has no upload server/token. '
                . 'Create the job with process=false and upload before starting it.'
            );
        }

        [$stream, $resolvedName] = $this->resolve($file, $filename);

        $multipart = new MultipartStream([
            [
                'name' => 'file',
                'contents' => $stream,
                'filename' => $resolvedName,
            ],
        ]);

        $url = rtrim($job->server, '/') . '/upload-file/' . $job->id;
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('X-Api2convert-Token', $job->token)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $multipart->getBoundary())
            ->withBody($multipart);

        return InputFile::fromArray($this->transport->interpret($this->transport->send($request)));
    }

    /**
     * @param string|resource|StreamInterface $file
     * @return array{0: StreamInterface, 1: string}
     */
    private function resolve(mixed $file, ?string $filename): array
    {
        if (is_string($file)) {
            $path = realpath($file);
            if ($path === false || !is_file($path)) {
                throw new Api2ConvertException("Input file not found: {$file}");
            }

            return [Utils::streamFor(Utils::tryFopen($path, 'r')), $filename ?? basename($path)];
        }

        return [Utils::streamFor($file), $filename ?? 'file'];
    }
}
