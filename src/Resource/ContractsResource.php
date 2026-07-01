<?php

declare(strict_types=1);

namespace Api2Convert\Resource;

use Api2Convert\Http\Transport;

/**
 * Information about the account's active contracts. Free-form response, returned
 * as a decoded array.
 */
final class ContractsResource
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @return array<int|string, mixed>
     */
    public function get(): array
    {
        return $this->transport->request('GET', '/contracts');
    }
}
