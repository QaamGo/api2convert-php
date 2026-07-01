<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * The request was rejected as invalid (HTTP 400) — e.g. an unknown conversion
 * target or a malformed option. The API message usually names the problem.
 */
class ValidationException extends ApiException
{
}
