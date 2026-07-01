<?php

declare(strict_types=1);

namespace Api2Convert\Exception;

/**
 * The API key is missing, invalid or not allowed to perform the request (HTTP 401 / 403).
 */
class AuthenticationException extends ApiException
{
}
