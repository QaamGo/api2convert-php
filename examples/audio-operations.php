<?php

/**
 * Audio operations — transcode a WAV to stereo 192 kbps AAC.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/audio-operations.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    $result = $client->convert(
        'https://example-files.online-convert.com/audio/wav/example.wav',
        'aac',
        [
            'audio_codec' => 'aac',
            'audio_bitrate' => 192,
            'channels' => 'stereo',
            'frequency' => 44100,
        ],
        'audio',
    );

    $path = $result->save(getcwd() . '/example.aac');
    echo "Saved: {$path}\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}
