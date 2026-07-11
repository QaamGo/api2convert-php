<?php

declare(strict_types=1);

namespace Api2Convert\Resource;

use Api2Convert\Http\Transport;

/**
 * API usage statistics. The response shape is free-form, so these return the
 * decoded array as-is.
 *
 * `$filter` is `single` (only the calling API key) or `all` (every key on the account, the default).
 * The request is scoped by the `X-Api2convert-Api-Key` header, so never pass a key as `$filter`.
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
        return $this->transport->request(
            'GET',
            '/stats/day/' . Transport::segment($day) . '/' . Transport::segment($filter),
        );
    }

    /**
     * @param string $month Format `yyyy-mm`.
     * @return array<int|string, mixed>
     */
    public function month(string $month, string $filter = 'all'): array
    {
        return $this->transport->request(
            'GET',
            '/stats/month/' . Transport::segment($month) . '/' . Transport::segment($filter),
        );
    }

    /**
     * @param string $year Format `yyyy`.
     * @return array<int|string, mixed>
     */
    public function year(string $year, string $filter = 'all'): array
    {
        return $this->transport->request(
            'GET',
            '/stats/year/' . Transport::segment($year) . '/' . Transport::segment($filter),
        );
    }
}
