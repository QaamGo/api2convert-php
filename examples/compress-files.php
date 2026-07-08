<?php

/**
 * Compress files — shrink a JPG with a high compression level.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/compress-files.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    $result = $client->convert(
        'https://example-files.online-convert.com/raster%20image/jpg/example.jpg',
        'compress',
        ['compression_level' => 'high'],
        'operation',
    );

    $path = $result->save(getcwd() . '/compressed/');
    echo "Saved: {$path}\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}
