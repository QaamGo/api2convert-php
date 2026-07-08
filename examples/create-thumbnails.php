<?php

/**
 * Create thumbnails — render the first page of a PDF as a 300px PNG thumbnail.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/create-thumbnails.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    $result = $client->convert(
        'https://example-files.online-convert.com/document/pdf/example.pdf',
        'thumbnail',
        [
            'thumbnail_target' => 'png',
            'width' => 300,
            'pages' => 'first',
            'dpi' => 150,
        ],
        'operation',
    );

    $path = $result->save(getcwd() . '/thumbnail/');
    echo "Saved: {$path}\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}
