<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * The requested resource (job, input, output, preset, …) does not exist (HTTP 404).
 */
class NotFoundException extends ApiException
{
}
