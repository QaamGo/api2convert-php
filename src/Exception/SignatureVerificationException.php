<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * A webhook payload could not be verified against the provided signature/secret.
 *
 * Treat this as a security event: do not trust or process the payload.
 */
class SignatureVerificationException extends Api2ConvertException
{
}
