<?php

/**
 * Statistics — read your API usage for a given month.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/statistics.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    $stats = $client->stats()->month('2026-06');

    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Request failed: ' . $e->getMessage() . "\n");
    exit(1);
}
