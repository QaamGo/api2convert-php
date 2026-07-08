<?php

/**
 * Create archives — bundle two remote files into a single ZIP.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/create-archives.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY
$jobs = $client->jobs();

try {
    $job = $jobs->create([
        'process' => true,
        'input' => [
            ['type' => 'remote', 'source' => 'https://example-files.online-convert.com/document/pdf/example.pdf'],
            ['type' => 'remote', 'source' => 'https://example-files.online-convert.com/raster%20image/png/example.png'],
        ],
        'conversion' => [
            ['category' => 'archive', 'target' => 'zip'],
        ],
    ]);

    $finished = $jobs->wait($job->id);
    echo "Status: {$finished->status->code}\n";

    foreach ($finished->output as $output) {
        echo "Output: {$output->uri}\n";
    }
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}
