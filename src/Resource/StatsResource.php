<?php

declare(strict_types=1);

namespace Api2Convert\Resource;

use Api2Convert\Http\Transport;

/**
 * API usage statistics. The response shape is free-form, so these return the
 * decoded array as-is.
 *
 * `$filter` is either an API key to scope to, or `all`.
 */
final class StatsResource
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @param string $day Format `yyyy-mm-dd`.
     * @return array<int|string, mixed>
     */
    public function day(string $day, string $filter = 'all'): array
    {
        return $this->transport->request('GET', "/stats/day/{$day}/{$filter}");
    }

    /**
     * @param string $month Format `yyyy-mm`.
     * @return array<int|string, mixed>
     */
    public function month(string $month, string $filter = 'all'): array
    {
        return $this->transport->request('GET', "/stats/month/{$month}/{$filter}");
    }

    /**
     * @param string $year Format `yyyy`.
     * @return array<int|string, mixed>
     */
    public function year(string $year, string $filter = 'all'): array
    {
        return $this->transport->request('GET', "/stats/year/{$year}/{$filter}");
    }
}
