# API2Convert PHP SDK

[![CI](https://github.com/QaamGo/api2convert-php/actions/workflows/ci.yml/badge.svg)](https://github.com/QaamGo/api2convert-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/api2convert/sdk)](https://packagist.org/packages/api2convert/sdk)
![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.2-777bb4)
![License](https://img.shields.io/badge/license-MIT-green)

The official PHP client for the [API2Convert](https://www.api2convert.com) file-conversion API.
Convert, compress and transform **images, documents, audio, video, ebooks, archives and CAD** — and
run operations like OCR, merge, thumbnail and website capture — in one line of code.

```php
$client = new Api2Convert\Api2Convert('YOUR_API_KEY');

$client->convert('invoice.docx', 'pdf')->save('invoice.pdf');
```

That single call creates a job, uploads your file, starts it, waits for it to finish and gives you
back a result you can save. No polling loops, no manual upload handling.

## Requirements

- PHP 8.2+
- A [Guzzle](https://docs.guzzlephp.org/) HTTP client (or any other [PSR-18](https://www.php-fig.org/psr/psr-18/) client)

## Install

```bash
composer require api2convert/sdk guzzlehttp/guzzle
```

Get an API key from the [API2Convert dashboard / documentation](https://www.api2convert.com/documentation).

## Quick start

```php
require 'vendor/autoload.php';

use Api2Convert\Api2Convert;

// Reads the API2CONVERT_API_KEY environment variable when no key is passed.
$client = new Api2Convert('YOUR_API_KEY');

// 1) From a local file
$client->convert('photo.png', 'jpg')->save('photo.jpg');

// 2) From a URL
$client->convert('https://example.com/photo.png', 'jpg')->save('photo.jpg');

// 3) With conversion options (discover them via $client->options('jpg'))
$client->convert('photo.png', 'jpg', [
    'quality' => 85, 'width' => 1280, 'height' => 720,
])->save('out/');   // a directory → the API filename is kept
```

`convert($input, $to, $options = [])` — `$input` is a **local path, a public URL, or an open
stream/resource**; `$to` is the **target format**; `$options` are the **conversion options** for
that target. Less-common controls are named arguments: `category`, `timeout`, `outputIndex`,
`filename`. The returned `ConversionResult` lets you:

```php
$result = $client->convert('report.docx', 'pdf');

$result->save('report.pdf');       // stream to a file
$result->save('downloads/');       // ...or a directory (keeps the server filename)
$content = $result->contents();    // ...or get the raw bytes
$url     = $result->url();          // ...or just the download URL
```

## Asynchronous conversions & webhooks

For long-running jobs, start the conversion and get notified via a webhook instead of waiting:

```php
$job = $client->convertAsync('movie.mov', 'mp4', callback: 'https://your-app.example.com/webhooks/api2convert');
```

In your webhook handler, verify and parse the callback:

```php
use Api2Convert\Api2Convert;
use Api2Convert\Exception\SignatureVerificationException;

$payload   = file_get_contents('php://input');           // the RAW body
$signature = $_SERVER['HTTP_X_OC_SIGNATURE'] ?? null;

try {
    $event = Api2Convert::webhooks()->constructEvent($payload, $signature, 'YOUR_WEBHOOK_SECRET');
    $job   = $event->job;
    // … react to $job->status->code …
} catch (SignatureVerificationException $e) {
    http_response_code(400);
}
```

> Signed webhooks are being rolled out. Until they are enabled for your account no signature is
> sent — call `Api2Convert::webhooks()->parse($payload)` (or pass an empty secret) to deserialize
> the callback without verifying.

## Error handling

Every failure is a typed exception extending `Api2Convert\Exception\Api2ConvertException`:

```php
use Api2Convert\Exception\ConversionFailedException;
use Api2Convert\Exception\RateLimitException;
use Api2Convert\Exception\ValidationException;
use Api2Convert\Exception\AuthenticationException;

try {
    $client->convert('photo.png', 'jpg')->save('photo.jpg');
} catch (ValidationException $e) {
    // bad target / option — $e->getMessage() explains
} catch (AuthenticationException $e) {
    // bad or missing API key
} catch (RateLimitException $e) {
    // too many requests — retry after $e->retryAfter seconds
} catch (ConversionFailedException $e) {
    // the job failed — inspect $e->errors()
}
```

| Exception | When |
|---|---|
| `AuthenticationException` | 401 / 403 — bad or missing key |
| `PaymentRequiredException` | 402 — no remaining quota |
| `ValidationException` | 400 — invalid request (e.g. unknown target) |
| `NotFoundException` | 404 — resource doesn't exist |
| `RateLimitException` | 429 — exposes `->retryAfter` |
| `ServerException` | 5xx |
| `ConversionFailedException` | the job reached `failed`; exposes `->job` and `->errors()` |
| `TimeoutException` | the job didn't finish within the poll timeout |
| `SignatureVerificationException` | a webhook payload failed verification |

Transient failures (429, 5xx, network errors) are **retried automatically** with exponential backoff.

## Power user: the full job API

`convert()` is sugar over the Jobs API. Drop down to it for compound jobs, merges, presets, custom
polling or job chaining:

```php
$job = $client->jobs()->create([
    'process'    => false,
    'conversion' => [['target' => 'pdf', 'options' => ['pdf_a' => true]]],
]);

$client->jobs()->upload($job, 'contract.docx');           // local file
$client->jobs()->addInput($job->id, [                     // ...or a URL
    'type' => 'remote', 'source' => 'https://example.com/appendix.docx',
]);

$client->jobs()->start($job->id);
$done = $client->jobs()->wait($job->id, timeoutSeconds: 120);

foreach ($done->output as $output) {
    $client->download($output)->save('out/');
}
```

Available resources: `jobs()`, `conversions()` (the catalog + option discovery), `presets()`,
`stats()`, `contracts()`.

Discover the valid options for any target:

```php
$options = $client->options('jpg');            // → { quality: {...}, width: {...}, ... }
```

## Configuration

```php
$client = new Api2Convert('YOUR_API_KEY', [
    'timeout'         => 30,    // per-request network timeout (seconds)
    'maxRetries'      => 2,     // automatic retries for transient failures
    'pollInterval'    => 1.0,   // first poll interval when waiting (seconds)
    'pollMaxInterval' => 5.0,   // backoff cap (seconds)
    'pollTimeout'     => 300,   // give up waiting after this many seconds
]);
```

Bring your own PSR-18 client (e.g. Symfony HttpClient) by passing it as the third argument.

## Security — never publish your API key

- **Never hard-code or commit your API key.** Load it from the environment (`API2CONVERT_API_KEY`)
  or a secrets manager.
- In CI, store it as a **masked & protected** variable (this repo's pipeline reads
  `$API2CONVERT_API_KEY`) and never print it to logs.
- Treat the per-job upload **token** and your **webhook signing secret** with the same care.
- The SDK never logs your key/token and never puts them in exception messages.
- If a key is ever exposed, **revoke and rotate it** in the API2Convert dashboard immediately.

## Development

```bash
composer install
composer check        # phpcs + phpstan + phpunit
```

Live conformance tests run against the real API when `API2CONVERT_API_KEY` is set:

```bash
API2CONVERT_API_KEY=... vendor/bin/phpunit --testsuite live
```

This SDK is hand-written and kept in sync with the API by an AI agent — see [`AGENTS.md`](AGENTS.md)
and [`docs/SDK_CONTRACT.md`](docs/SDK_CONTRACT.md). Notable changes are recorded in
[`docs/CHANGELOG.md`](docs/CHANGELOG.md).

## License

MIT — see [`LICENSE`](LICENSE).
