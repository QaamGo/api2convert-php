<?php

/**
 * Rate limits — inspect the account's contracts (quota and limits).
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/rate-limits.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    $contracts = $client->contracts()->get();

    echo json_encode($contracts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Request failed: ' . $e->getMessage() . "\n");
    exit(1);
}
