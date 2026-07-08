<?php

/**
 * Job lifecycle — drive the steps by hand: create → add input → start → wait.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/job-lifecycle.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY
$jobs = $client->jobs();

try {
    // 1) Stage a job (process: false) so we can attach inputs before starting.
    $job = $jobs->create([
        'process' => false,
        'conversion' => [
            ['category' => 'image', 'target' => 'png'],
        ],
    ]);
    echo "Created job: {$job->id}\n";

    // 2) Attach a remote input.
    $jobs->addInput($job->id, [
        'type' => 'remote',
        'source' => 'https://example-files.online-convert.com/raster%20image/jpg/example.jpg',
    ]);

    // 3) Start processing, then poll to a terminal status.
    $jobs->start($job->id);
    $finished = $jobs->wait($job->id);
    echo "Status: {$finished->status->code}\n";

    // 4) Inspect the produced outputs.
    foreach ($jobs->outputs($job->id) as $output) {
        echo "Output: {$output->uri}\n";
    }
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}
