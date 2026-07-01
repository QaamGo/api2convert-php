<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

use Api2Convert\Model\Job;
use Api2Convert\Model\JobMessage;

/**
 * The job reached the `failed` status. The originating {@see Job} is attached so
 * you can inspect its errors and warnings.
 */
class ConversionFailedException extends Api2ConvertException
{
    /**
     * @param Job          $job      The failed job, including its `errors` and `warnings`.
     */
    public function __construct(
        public readonly Job $job,
        ?string $message = null,
    ) {
        parent::__construct($message ?? self::buildMessage($job));
    }

    /**
     * The job's errors (may be empty if the API gave no detail).
     *
     * @return list<JobMessage>
     */
    public function errors(): array
    {
        return $this->job->errors;
    }

    private static function buildMessage(Job $job): string
    {
        $first = $job->errors[0] ?? null;
        if ($first !== null) {
            $code = $first->code !== null ? " (code {$first->code})" : '';

            return "Conversion failed: {$first->message}{$code}";
        }

        $info = $job->status->info;

        return 'Conversion failed' . ($info !== null ? ": {$info}" : '.');
    }
}
