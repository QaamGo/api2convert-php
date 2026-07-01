<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Model\Job;
use PHPUnit\Framework\TestCase;

final class JobModelTest extends TestCase
{
    public function testHydratesFromApiPayload(): void
    {
        $job = Job::fromArray([
            'id' => '8daae6d1-26e0-11e5-b2a1-0800273b325b',
            'token' => 'tok',
            'server' => 'https://www2.api2convert.com/v2',
            'status' => ['code' => 'completed', 'info' => 'done'],
            'conversion' => [['target' => 'png', 'category' => 'image', 'options' => ['quality' => 85]]],
            'input' => [['id' => 'in', 'type' => 'remote', 'source' => 'https://x/y.jpg']],
            'output' => [['id' => 'o', 'uri' => 'https://dl/result.png', 'filename' => 'result.png', 'size' => 2048]],
            'warnings' => [['code' => 1, 'message' => 'heads up']],
        ]);

        self::assertSame('8daae6d1-26e0-11e5-b2a1-0800273b325b', $job->id);
        self::assertSame('tok', $job->token);
        self::assertTrue($job->isCompleted());
        self::assertFalse($job->isFailed());
        self::assertTrue($job->isTerminal());
        self::assertSame('png', $job->conversion[0]->target);
        self::assertSame(85, $job->conversion[0]->options['quality']);
        self::assertSame('remote', $job->input[0]->type);
        self::assertSame(2048, $job->output[0]->size);
        self::assertSame('result.png', $job->output[0]->filename);
        self::assertCount(1, $job->warnings);
        self::assertCount(0, $job->errors);
    }

    public function testUnknownStatusIsNonTerminal(): void
    {
        $job = Job::fromArray(['id' => 'j', 'status' => ['code' => 'something_new']]);

        self::assertFalse($job->isTerminal());
        self::assertFalse($job->isCompleted());
    }

    public function testToleratesMissingFields(): void
    {
        $job = Job::fromArray(['id' => 'j', 'status' => ['code' => 'incomplete']]);

        self::assertSame([], $job->output);
        self::assertNull($job->token);
    }
}
