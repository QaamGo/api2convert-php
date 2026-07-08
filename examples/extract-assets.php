<?php

/**
 * Extract assets — pull the embedded assets out of a DOCX file.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/extract-assets.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    $result = $client->convert(
        'https://example-files.online-convert.com/document/docx/example.docx',
        'extract-assets',
        [],
        'operation',
    );

    foreach ($result->outputs() as $output) {
        echo "Output: {$output->uri}\n";
    }
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}
