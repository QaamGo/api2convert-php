<?php

/**
 * Convert files — browse the conversions catalog, then convert a JPG to PNG.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/convert-files.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    // The full catalog: every { id, category, target, options } the API supports.
    $all = $client->conversions()->list();
    echo 'Catalog entries: ' . count($all) . "\n";

    // Narrow it to conversions that target PNG.
    $toPng = $client->conversions()->list(target: 'png');
    echo 'Conversions to png: ' . count($toPng) . "\n";

    // Now run one of them: convert the example JPG to PNG.
    $result = $client->convert(
        'https://example-files.online-convert.com/raster%20image/jpg/example.jpg',
        'png',
    );

    $path = $result->save(getcwd() . '/example.png');
    echo "Saved: {$path}\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}
