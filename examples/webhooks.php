<?php

/**
 * Webhooks — start an async conversion and get notified via a callback URL.
 *
 * This runnable script starts a job with a `callback`; the second half shows the
 * matching receiver you would host at that URL to verify and handle the payload.
 *
 * Run with:
 *   API2CONVERT_API_KEY=your-key php examples/webhooks.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;
use Api2Convert\Exception\SignatureVerificationException;

$client = new Api2Convert(); // reads API2CONVERT_API_KEY

try {
    // Start the conversion without waiting. API2Convert POSTs the finished job to
    // your callback URL once it changes status — no polling required.
    $job = $client->convertAsync(
        'https://example-files.online-convert.com/document/docx/example.docx',
        'pdf',
        callback: 'https://your-app.example.com/api2convert/webhook',
        category: 'document',
    );

    echo "Started job {$job->id} (status: {$job->status->code}); the webhook fires when it finishes.\n";
} catch (Api2ConvertException $e) {
    fwrite(STDERR, 'Could not start job: ' . $e->getMessage() . "\n");
    exit(1);
}

// --- The receiver you host at the callback URL --------------------------------
//
// Deploy the handler below at https://your-app.example.com/api2convert/webhook.
// It verifies the signature (fail closed on a missing secret) and reacts to the
// finished job. It is inert here because there is no incoming HTTP request.
if (PHP_SAPI !== 'cli') {
    $secret = getenv('API2CONVERT_WEBHOOK_SECRET') ?: '';
    if ($secret === '') {
        // An empty secret makes constructEvent() skip verification; refuse rather
        // than trust an unverified body. If signed webhooks are not yet enabled for
        // your account, switch deliberately to Api2Convert::webhooks()->parse().
        error_log('API2CONVERT_WEBHOOK_SECRET is not set; refusing unverified webhooks.');
        http_response_code(500);
        echo 'server misconfigured';
        exit;
    }

    $payload = file_get_contents('php://input') ?: '';
    $signature = $_SERVER['HTTP_X_OC_SIGNATURE'] ?? null;

    try {
        $event = Api2Convert::webhooks()->constructEvent($payload, $signature, $secret);
    } catch (SignatureVerificationException $e) {
        http_response_code(400);
        echo 'invalid signature';
        exit;
    }

    $delivered = $event->job;
    if ($delivered->isCompleted()) {
        foreach ($delivered->output as $output) {
            error_log("Job {$delivered->id} done: {$output->uri}");
        }
    } elseif ($delivered->isFailed()) {
        error_log("Job {$delivered->id} failed: " . ($delivered->errors[0]->message ?? 'unknown'));
    }

    http_response_code(200);
    echo 'ok';
}
