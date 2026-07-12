<?php

declare(strict_types=1);

namespace Api2Convert\Resource;

use Api2Convert\Exception\ConversionFailedException;
use Api2Convert\Exception\TimeoutException;
use Api2Convert\Http\Config;
use Api2Convert\Http\Transport;
use Api2Convert\Input\CloudInput;
use Api2Convert\Model\InputFile;
use Api2Convert\Model\Job;
use Api2Convert\Model\OutputFile;
use Api2Convert\Support\Data;
use Api2Convert\Upload\FileUploader;
use Psr\Http\Message\StreamInterface;

/**
 * The Jobs resource — full control over the job lifecycle.
 *
 * Most users only need `$client->convert()`, which is built on top of these
 * methods. Reach for this resource for compound jobs, merges, presets, custom
 * polling or job chaining.
 */
final class JobsResource
{
    public function __construct(
        private readonly Transport $transport,
        private readonly FileUploader $uploader,
    ) {
    }

    /**
     * Create a job. Pass `'process' => false` to stage it for uploads, then call
     * {@see start()} once inputs are attached.
     *
     * @param array<string, mixed> $payload          Job body: `conversion`, optional `input`, `process`, `callback`, …
     * @param string|null          $idempotencyKey   Optional key making create retry-safe (`Idempotency-Key` header).
     */
    public function create(array $payload, ?string $idempotencyKey = null): Job
    {
        $headers = $idempotencyKey !== null ? ['Idempotency-Key' => $idempotencyKey] : [];

        return Job::fromArray($this->transport->request('POST', '/jobs', $payload, [], $headers));
    }

    public function get(string $jobId): Job
    {
        return Job::fromArray($this->transport->request('GET', '/jobs/' . Transport::segment($jobId)));
    }

    /**
     * List the current key's jobs (paginated, 50 per page).
     *
     * @return list<Job>
     */
    public function list(?string $status = null, int $page = 1): array
    {
        $query = ['page' => (string) $page];
        if ($status !== null) {
            $query['status'] = $status;
        }

        return Data::mapObjects($this->transport->request('GET', '/jobs', null, $query), Job::fromArray(...));
    }

    /**
     * Modify a job. The common case — starting a staged job — has the dedicated
     * {@see start()} helper.
     *
     * @param array<string, mixed> $payload
     */
    public function update(string $jobId, array $payload): Job
    {
        return Job::fromArray($this->transport->request('PATCH', '/jobs/' . Transport::segment($jobId), $payload));
    }

    /**
     * Start processing a staged job (`process => true`).
     */
    public function start(string $jobId): Job
    {
        return $this->update($jobId, ['process' => true]);
    }

    /**
     * Cancel a job (whether staged or processing).
     */
    public function cancel(string $jobId): void
    {
        $this->transport->request('DELETE', '/jobs/' . Transport::segment($jobId));
    }

    /**
     * Attach an input — a {@see CloudInput} builder, or a raw descriptor array, e.g. a remote URL
     * (`addInput($id, ['type' => 'remote', 'source' => 'https://…'])`) or a Google Drive picker
     * (`['type' => 'gdrive_picker', 'source' => $fileId, 'credentials' => ['token' => …]]`).
     *
     * @param CloudInput|array<string, mixed> $input
     */
    public function addInput(string $jobId, CloudInput|array $input): InputFile
    {
        $descriptor = $input instanceof CloudInput ? $input->toArray() : $input;

        return InputFile::fromArray(
            $this->transport->request('POST', '/jobs/' . Transport::segment($jobId) . '/input', $descriptor)
        );
    }

    /**
     * Upload a local file (path, resource or stream) to the job's upload server.
     *
     * @param string|resource|StreamInterface $file
     */
    public function upload(Job $job, mixed $file, ?string $filename = null): InputFile
    {
        return $this->uploader->upload($job, $file, $filename);
    }

    /**
     * Block until the job reaches a terminal status, polling with backoff.
     *
     * @param int|null $timeoutSeconds Overrides the configured poll timeout (clamped to a sane maximum).
     * @param bool     $throwOnFailure When true (default), a failed/canceled job throws (see @throws below).
     *
     * @throws ConversionFailedException When the job fails/is canceled and $throwOnFailure is true.
     * @throws TimeoutException          When the timeout elapses before completion.
     */
    public function wait(string $jobId, ?int $timeoutSeconds = null, bool $throwOnFailure = true): Job
    {
        $config = $this->transport->config();

        // Clamp again here (Config::create already clamps) so a directly-constructed
        // Config or a per-call override can never busy-loop or poll unbounded.
        $timeout = min(Config::MAX_POLL_TIMEOUT, max(0, $timeoutSeconds ?? $config->pollTimeout));
        $maxInterval = max(Config::MIN_POLL_INTERVAL, $config->pollMaxInterval);
        $interval = max(Config::MIN_POLL_INTERVAL, $config->pollInterval);
        $deadline = microtime(true) + $timeout;

        while (true) {
            $job = $this->get($jobId);

            if (($job->isFailed() || $job->isCanceled()) && $throwOnFailure) {
                throw new ConversionFailedException($job);
            }

            if ($job->isTerminal()) {
                return $job;
            }

            if (microtime(true) >= $deadline) {
                throw new TimeoutException($job, $timeout);
            }

            $this->transport->pause($interval);
            $interval = min($maxInterval, $interval * 1.5);
        }
    }

    /**
     * Outputs produced by the job (use {@see get()} first, or {@see wait()}).
     *
     * @return list<OutputFile>
     */
    public function outputs(string $jobId): array
    {
        return Data::mapObjects(
            $this->transport->request('GET', '/jobs/' . Transport::segment($jobId) . '/output'),
            OutputFile::fromArray(...),
        );
    }
}
