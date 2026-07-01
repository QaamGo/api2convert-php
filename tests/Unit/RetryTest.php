<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Tests\TestCase;

final class RetryTest extends TestCase
{
    public function testRetriesTransientStatusThenSucceeds(): void
    {
        $this->http->addResponse($this->jsonResponse(503, ['message' => 'temporary']));
        $this->http->addResponse($this->jsonResponse(429, ['message' => 'slow down']));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'job-1', 'status' => ['code' => 'completed']]));

        $job = $this->client(['maxRetries' => 2])->jobs()->get('job-1');

        self::assertSame('job-1', $job->id);
        self::assertCount(3, $this->http->getRequests());
    }

    public function testRetriesNetworkErrorThenSucceeds(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', 'https://api.api2convert.com/v2/jobs/job-2');
        $this->http->addException(new \GuzzleHttp\Exception\ConnectException('connection reset', $request));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'job-2', 'status' => ['code' => 'completed']]));

        $job = $this->client(['maxRetries' => 1])->jobs()->get('job-2');

        self::assertSame('job-2', $job->id);
        self::assertCount(2, $this->http->getRequests());
    }
}
