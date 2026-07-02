<?php

/**
 * Example webhook receiver. Point a job's `callback` at this endpoint and verify
 * the payload before trusting it.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Api2Convert\Api2Convert;
use Api2Convert\Exception\SignatureVerificationException;

// Fail closed: an empty secret makes constructEvent() skip signature verification
// entirely, so refuse to run rather than trust an unverified body. If your account
// has not enabled signed webhooks yet, confirm that and switch deliberately to
// Api2Convert::webhooks()->parse($payload) instead of leaving the secret unset.
$secret = getenv('API2CONVERT_WEBHOOK_SECRET') ?: '';
if ($secret === '') {
    error_log('API2CONVERT_WEBHOOK_SECRET is not set; refusing to accept unverified webhooks.');
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

$job = $event->job;

if ($job->isCompleted()) {
    foreach ($job->output as $output) {
        // e.g. enqueue a download of $output->uri
        error_log("Job {$job->id} done: {$output->uri}");
    }
} elseif ($job->isFailed()) {
    error_log("Job {$job->id} failed: " . ($job->errors[0]->message ?? 'unknown'));
}

http_response_code(200);
echo 'ok';
