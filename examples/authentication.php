<?php

/**
 * Authentication — verify the API key works by listing your jobs.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/authentication.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\AuthenticationException;
use Api2Convert\Exception\Api2ConvertException;

// The constructor reads API2CONVERT_API_KEY. Every request authenticates with it
// via the X-Oc-Api-Key header — the SDK never logs it or puts it in an error.
$client = new Api2Convert();

try {
    $jobs = $client->jobs()->list();
    echo 'Authenticated. Jobs on this key: ' . count($jobs) . "\n";
} catch (AuthenticationException $e) {
    fwrite(STDERR, 'Authentication failed (bad or missing key): ' . $e->getMessage() . "\n");
    exit(1);
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Request failed: ' . $e->getMessage() . "\n");
    exit(1);
}
