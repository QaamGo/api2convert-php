<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * The SDK was constructed with invalid configuration — e.g. a missing API key.
 *
 * Thrown before any network call is attempted. Extends {@see Api2ConvertException}
 * so a single `catch` around client construction covers it alongside every other
 * SDK failure.
 */
class ConfigurationException extends Api2ConvertException
{
}
