<?php

/**
 * Capture a website — screenshot a page and deliver it as a PNG.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/capture-website.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY
$jobs = $client->jobs();

try {
    // The input's `screenshot` engine renders the URL; the conversion emits a PNG.
    $job = $jobs->create([
        'process' => true,
        'input' => [
            [
                'type' => 'remote',
                'source' => 'https://www.online-convert.com',
                'engine' => 'screenshot',
                'options' => [
                    'screen_width' => 1280,
                    'screen_height' => 1024,
                    'device_scale_factor' => 1,
                ],
            ],
        ],
        'conversion' => [
            ['category' => 'image', 'target' => 'png'],
        ],
    ]);

    $finished = $jobs->wait($job->id);
    echo "Status: {$finished->status->code}\n";

    foreach ($finished->output as $output) {
        echo "Output: {$output->uri}\n";
    }
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Capture failed: ' . $e->getMessage() . "\n");
    exit(1);
}
