<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * Base class for every exception thrown by the SDK.
 *
 * Catch this if you want to handle any SDK failure in one place; catch a more
 * specific subclass (e.g. {@see RateLimitException}, {@see ConversionFailedException})
 * to react to a particular failure mode.
 */
class Api2ConvertException extends \RuntimeException
{
}
