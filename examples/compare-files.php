<?php

/**
 * Compare files — diff two images with the SSIM method and a red diff overlay.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/compare-files.php
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
            [
                'type' => 'remote',
                'source' => 'https://example-files.online-convert.com/raster%20image/jpg/example_small.jpg',
            ],
            ['type' => 'remote', 'source' => 'https://example-files.online-convert.com/raster%20image/jpg/example.jpg'],
        ],
        'conversion' => [
            [
                'category' => 'operation',
                'target' => 'compare-image',
                'options' => ['method' => 'ssim', 'threshold' => 5, 'diff_color' => 'red'],
            ],
        ],
    ]);

    $finished = $jobs->wait($job->id);
    echo "Status: {$finished->status->code}\n";

    foreach ($finished->output as $output) {
        echo "Output: {$output->uri}\n";
    }
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Comparison failed: ' . $e->getMessage() . "\n");
    exit(1);
}
