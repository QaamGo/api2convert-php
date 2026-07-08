<?php

/**
 * Quickstart — convert a remote JPG to PNG, look the job up, then download it.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/quickstart.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    // 1) Convert a public URL to PNG and wait for the result.
    $result = $client->convert(
        'https://example-files.online-convert.com/raster%20image/jpg/example.jpg',
        'png',
    );

    // 2) Look the finished job up by its id.
    $job = $client->jobs()->get($result->job->id);
    echo "Job {$job->id} status: {$job->status->code}\n";

    // 3) Download the produced file.
    $path = $result->save(getcwd() . '/example.png');
    echo "Saved: {$path}\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}
