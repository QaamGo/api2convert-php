<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * A request did not yield a usable response. Two throw sites:
 *
 * - a transport-level failure (DNS, connection, TLS or read failure) — retried
 *   automatically for idempotent requests and thrown once retries are exhausted;
 * - a successful (2xx) response whose body is not valid JSON (e.g. an intermediary
 *   HTML/error page) — thrown directly by the decoder and not retried.
 */
class NetworkException extends Api2ConvertException
{
}
