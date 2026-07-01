<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * The API returned a server error (HTTP 5xx). These are retried automatically;
 * this is thrown only once retries are exhausted.
 */
class ServerException extends ApiException
{
}
