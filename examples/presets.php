<?php

/**
 * Presets — list saved conversion presets for a category and target.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/presets.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    $presets = $client->presets()->list(category: 'video', target: 'mp4');

    echo 'Presets found: ' . count($presets) . "\n";
    foreach ($presets as $preset) {
        echo "- {$preset->name} ({$preset->target})\n";
    }
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Request failed: ' . $e->getMessage() . "\n");
    exit(1);
}
