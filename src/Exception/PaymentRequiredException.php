<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * The account has no remaining quota / credit for this request (HTTP 402).
 */
class PaymentRequiredException extends ApiException
{
}
