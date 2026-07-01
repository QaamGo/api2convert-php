<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

use Api2Convert\Model\Job;

/**
 * A job did not reach a terminal status within the configured poll timeout.
 *
 * The job is still running server-side — re-fetch it later with
 * `$client->jobs()->get($job->id)` or raise the timeout.
 */
class TimeoutException extends Api2ConvertException
{
    public function __construct(
        public readonly Job $job,
        int $timeoutSeconds,
    ) {
        parent::__construct(
            "Timed out after {$timeoutSeconds}s waiting for job {$job->id} to finish "
            . "(last status: {$job->status->code})."
        );
    }
}
