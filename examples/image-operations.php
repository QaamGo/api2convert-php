<?php

/**
 * Image operations — resize a JPG to 800x600, cropping to keep the aspect ratio.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/image-operations.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    $result = $client->convert(
        'https://example-files.online-convert.com/raster%20image/jpg/example.jpg',
        'resize-image',
        [
            'width' => 800,
            'height' => 600,
            'resize_by' => 'px',
            'resize_handling' => 'keep_aspect_ratio_crop',
        ],
        'operation',
    );

    $path = $result->save(getcwd() . '/resized/');
    echo "Saved: {$path}\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}
