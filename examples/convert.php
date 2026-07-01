<?php

/**
 * Minimal end-to-end example.
 *
 * Run with:  API2CONVERT_API_KEY=your-key php examples/convert.php path/to/file.docx pdf
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$input = $argv[1] ?? 'https://example-files.online-convert.com/raster%20image/jpg/example.jpg';
$target = $argv[2] ?? 'png';

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    $result = $client->convert($input, $target);
    $path = $result->save(getcwd() . '/');
    echo "Saved: {$path}\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}
