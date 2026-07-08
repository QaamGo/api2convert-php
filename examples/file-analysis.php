<?php

/**
 * File analysis — extract a JPG's metadata as JSON.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/file-analysis.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    $result = $client->convert(
        'https://example-files.online-convert.com/raster%20image/jpg/example.jpg',
        'json',
        [],
        'metadata',
    );

    // The analysis result is a JSON document; print it.
    echo $result->contents() . "\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Analysis failed: ' . $e->getMessage() . "\n");
    exit(1);
}
