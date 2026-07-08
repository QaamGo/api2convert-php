<?php

/**
 * Create hashes — compute the SHA-256 checksum of a remote ZIP.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/create-hashes.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    $result = $client->convert(
        'https://example-files.online-convert.com/archive/zip/example.zip',
        'sha256',
        [],
        'hash',
    );

    // The hash comes back as the job's output file; read its contents.
    echo 'SHA-256: ' . trim($result->contents()) . "\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}
