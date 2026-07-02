<?php

declare(strict_types=1);

namespace Api2Convert\Enum;

/**
 * Well-known job status codes (the `status.code` field).
 *
 * The API may introduce further codes; treat any code not listed here as
 * non-terminal. Use {@see JobStatus::isTerminal()} on a case, or
 * {@see JobStatus::isTerminalCode()} for a raw status string, rather than comparing
 * strings by hand.
 */
enum JobStatus: string
{
    case Created = 'created';
    case Incomplete = 'incomplete';
    case Downloading = 'downloading';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /**
     * A job in a terminal state is finished and will not change further.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed || $this === self::Canceled;
    }

    /**
     * Is the given raw status code a terminal one? Unknown codes are non-terminal.
     */
    public static function isTerminalCode(string $code): bool
    {
        return self::tryFrom($code)?->isTerminal() ?? false;
    }
}
