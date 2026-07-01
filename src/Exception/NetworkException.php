<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * The request could not be completed at the transport level (DNS, connection,
 * TLS or read failure). Retried automatically; thrown once retries are exhausted.
 */
class NetworkException extends Api2ConvertException
{
}
