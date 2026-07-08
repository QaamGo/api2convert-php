<?php

/**
 * Uploading files — convert a local file in one call (upload + convert + wait).
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/uploading-files.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

// A minimal valid 1x1 PNG, written to a temp path so we have a real local file
// to upload (in your app this would be a path the user picked).
$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVQI'
    . '12P4z8AAAgMBAJi3d7AAAAAASUVORK5CYII=',
);
$source = tempnam(sys_get_temp_dir(), 'a2c-') . '.png';
file_put_contents($source, $png);

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    // Hand convert() a local path: the SDK stages the job, uploads the file to
    // the per-job upload server, starts it, waits, and returns the result.
    $result = $client->convert($source, 'png');

    $path = $result->save(getcwd() . '/uploaded.png');
    echo "Saved: {$path}\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    @unlink($source);
}
