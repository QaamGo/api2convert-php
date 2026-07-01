<?php

declare(strict_types=1);

namespace Api2Convert\Model;

use Api2Convert\Enum\JobStatus;
use Api2Convert\Support\Data;

/**
 * A conversion job — the central API2Convert resource.
 *
 * Returned by every job operation. {@see $server} and {@see $token} are needed to
 * upload local files; {@see $output} holds the produced files once {@see isCompleted()}.
 */
final class Job
{
    /**
     * @param list<Conversion> $conversion
     * @param list<InputFile>  $input
     * @param list<OutputFile> $output
     * @param list<JobMessage> $errors
     * @param list<JobMessage> $warnings
     * @param array<string, mixed> $raw The full decoded response, for fields not surfaced as typed properties.
     */
    public function __construct(
        public readonly string $id,
        public readonly Status $status,
        public readonly ?string $token = null,
        public readonly ?string $server = null,
        public readonly ?string $callback = null,
        public readonly array $conversion = [],
        public readonly array $input = [],
        public readonly array $output = [],
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::string($data['id'] ?? null),
            Status::fromArray(Data::object($data['status'] ?? null)),
            Data::nullableString($data['token'] ?? null),
            Data::nullableString($data['server'] ?? null),
            Data::nullableString($data['callback'] ?? null),
            Data::mapObjects($data['conversion'] ?? null, Conversion::fromArray(...)),
            Data::mapObjects($data['input'] ?? null, InputFile::fromArray(...)),
            Data::mapObjects($data['output'] ?? null, OutputFile::fromArray(...)),
            Data::mapObjects($data['errors'] ?? null, JobMessage::fromArray(...)),
            Data::mapObjects($data['warnings'] ?? null, JobMessage::fromArray(...)),
            $data,
        );
    }

    public function isCompleted(): bool
    {
        return $this->status->code === JobStatus::Completed->value;
    }

    public function isFailed(): bool
    {
        return $this->status->code === JobStatus::Failed->value;
    }

    /**
     * Finished (completed or failed) and will not change further.
     */
    public function isTerminal(): bool
    {
        return JobStatus::isTerminalCode($this->status->code);
    }
}
