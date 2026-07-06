<?php

declare(strict_types=1);

namespace Api2Convert;

use Api2Convert\Exception\ConfigurationException;
use Api2Convert\Http\Config;
use Api2Convert\Http\Transport;
use Api2Convert\Model\Job;
use Api2Convert\Model\OutputFile;
use Api2Convert\Resource\ContractsResource;
use Api2Convert\Resource\ConversionsResource;
use Api2Convert\Resource\JobsResource;
use Api2Convert\Resource\PresetsResource;
use Api2Convert\Resource\StatsResource;
use Api2Convert\Upload\FileUploader;
use Api2Convert\Webhook\WebhookVerifier;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * API2Convert client — convert, compress and transform files with one call.
 *
 * Quick start:
 * ```php
 * $client = new Api2Convert(getenv('API2CONVERT_API_KEY'));
 * $client->convert('invoice.docx', 'pdf')->save('invoice.pdf');
 * ```
 *
 * `convert()` hides the multi-step job lifecycle (create → upload → start → poll
 * → download). For full control, use {@see jobs()} and the other resources.
 */
final class Api2Convert
{
    public const VERSION = '10.2.0';

    private readonly Transport $transport;
    private readonly JobsResource $jobs;
    private readonly ConversionsResource $conversions;
    private readonly PresetsResource $presets;
    private readonly StatsResource $stats;
    private readonly ContractsResource $contracts;

    /**
     * @param string $apiKey  Your API key. Falls back to the `API2CONVERT_API_KEY` env var when empty.
     * @param array{
     *     baseUrl?: string,
     *     timeout?: int,
     *     maxRetries?: int,
     *     pollInterval?: float,
     *     pollMaxInterval?: float,
     *     pollTimeout?: int
     * } $options Client options (see {@see Config}).
     * @param ClientInterface|null         $httpClient     Inject any PSR-18 client (defaults to Guzzle).
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory (defaults to Guzzle's).
     * @param StreamFactoryInterface|null  $streamFactory  PSR-17 stream factory (defaults to Guzzle's).
     * @param \Closure(float): void|null   $sleeper        Overrides retry/poll sleeping. Internal — used by tests.
     */
    public function __construct(
        string $apiKey = '',
        array $options = [],
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?\Closure $sleeper = null,
    ) {
        $apiKey = $apiKey !== '' ? $apiKey : (string) (getenv('API2CONVERT_API_KEY') ?: '');
        if ($apiKey === '') {
            throw new ConfigurationException(
                'No API key provided. Pass it to the constructor or set the API2CONVERT_API_KEY environment variable.'
            );
        }

        $config = Config::create($apiKey, $options);
        $factory = new HttpFactory();
        $httpClient ??= new GuzzleClient([
            'timeout' => $config->timeout,
            'connect_timeout' => $config->timeout,
            'http_errors' => false,
            // Never let Guzzle follow a 3xx transparently: our custom X-Oc-Api-Key /
            // X-Oc-Token / X-Oc-Download-Password secrets ride in headers that Guzzle's
            // redirect middleware forwards across a cross-host hop, so an
            // auto-following client could leak them to a redirect target. The download
            // path re-implements the one legitimate (passwordless) redirect manually in
            // {@see Transport::download()}.
            'allow_redirects' => false,
        ]);

        $this->transport = new Transport(
            $httpClient,
            $requestFactory ?? $factory,
            $streamFactory ?? $factory,
            $config,
            $sleeper,
        );

        $uploader = new FileUploader($this->transport, $requestFactory ?? $factory);
        $this->jobs = new JobsResource($this->transport, $uploader);
        $this->conversions = new ConversionsResource($this->transport);
        $this->presets = new PresetsResource($this->transport);
        $this->stats = new StatsResource($this->transport);
        $this->contracts = new ContractsResource($this->transport);
    }

    /**
     * Convert a file and wait for the result.
     *
     * The happy path: hand it a local path, a public URL, or an open stream, name
     * the target format, and get back a result you can `save()`.
     *
     * ```php
     * $client->convert('photo.png', 'jpg')->save('photo.jpg');
     * $client->convert('photo.png', 'jpg', ['quality' => 85, 'width' => 1280])->save('out/');
     * ```
     *
     * @param string|resource|StreamInterface $input
     * @param string               $to          Target format, e.g. `pdf`, `jpg`, `mp4`.
     * @param array<string, mixed> $options     Target-specific conversion options (discover via {@see options()}).
     * @param string|null          $category    Conversion category, when a target is ambiguous.
     * @param int|null             $timeout     Override the poll timeout (seconds).
     * @param int|null             $outputIndex Which output file the result exposes (default 0).
     * @param string|null          $filename    Filename to advertise for an uploaded local file.
     * @param string|null          $downloadPassword Protect the result with this password; it is
     *                                                remembered and sent automatically on download.
     */
    public function convert(
        mixed $input,
        string $to,
        array $options = [],
        ?string $category = null,
        ?int $timeout = null,
        ?int $outputIndex = null,
        ?string $filename = null,
        ?string $downloadPassword = null,
    ): ConversionResult {
        $job = $this->startConversion($input, $to, $options, $category, null, $filename, $downloadPassword);
        $done = $this->jobs->wait($job->id, $timeout);

        return new ConversionResult($done, $this->transport, $outputIndex ?? 0, $downloadPassword);
    }

    /**
     * Start a conversion without waiting. Pass a `callback` URL to be notified, or
     * poll later with `jobs()->get($job->id)` / `jobs()->wait($job->id)`.
     *
     * @param string|resource|StreamInterface $input
     * @param string               $to       Target format, e.g. `pdf`, `jpg`, `mp4`.
     * @param array<string, mixed> $options  Target-specific conversion options.
     * @param string|null          $callback Webhook URL notified when the job's status changes.
     * @param string|null          $category Conversion category, when a target is ambiguous.
     * @param string|null          $filename Filename to advertise for an uploaded local file.
     * @param string|null          $downloadPassword Protect the result with this password; the
     *                                                `X-Oc-Download-Password` header is then
     *                                                required to download it.
     */
    public function convertAsync(
        mixed $input,
        string $to,
        array $options = [],
        ?string $callback = null,
        ?string $category = null,
        ?string $filename = null,
        ?string $downloadPassword = null,
    ): Job {
        return $this->startConversion($input, $to, $options, $category, $callback, $filename, $downloadPassword);
    }

    /**
     * Get a {@see FileDownload} for an output file: `$client->download($out)->save('./out/')`.
     *
     * @param string|null $downloadPassword Password protecting the output; remembered and sent
     *                                       automatically on download (overridable per call).
     */
    public function download(OutputFile $output, ?string $downloadPassword = null): FileDownload
    {
        return new FileDownload($this->transport, $output, $downloadPassword);
    }

    public function jobs(): JobsResource
    {
        return $this->jobs;
    }

    public function conversions(): ConversionsResource
    {
        return $this->conversions;
    }

    /**
     * Discover the valid options (type / enum / default / range) for a target
     * format: `$client->options('jpg')`. Pass `$category` to disambiguate if needed.
     *
     * @return array<string, mixed>
     */
    public function options(string $target, ?string $category = null): array
    {
        return $this->conversions->options($target, $category);
    }

    public function presets(): PresetsResource
    {
        return $this->presets;
    }

    public function stats(): StatsResource
    {
        return $this->stats;
    }

    public function contracts(): ContractsResource
    {
        return $this->contracts;
    }

    /**
     * Webhook verifier — usable without a configured client, e.g. in a controller:
     * `Api2Convert::webhooks()->constructEvent($rawBody, $signatureHeader, $secret)`.
     */
    public static function webhooks(): WebhookVerifier
    {
        return new WebhookVerifier();
    }

    /**
     * Build + start a job from a file/URL/stream input. Shared by convert() and
     * convertAsync(): a URL becomes a single remote-input job started immediately;
     * a local file/stream is staged, uploaded, then started.
     *
     * @param string|resource|StreamInterface $input
     * @param array<string, mixed>             $options
     */
    private function startConversion(
        mixed $input,
        string $to,
        array $options,
        ?string $category,
        ?string $callback,
        ?string $filename,
        ?string $downloadPassword = null,
    ): Job {
        $conversion = ['target' => $to];
        if ($category !== null) {
            $conversion['category'] = $category;
        }
        if ($options !== []) {
            $conversion['options'] = $options;
        }

        $job = ['conversion' => [$conversion]];
        if ($callback !== null) {
            $job['callback'] = $callback;
            $job['notify_status'] = true;
        }
        if ($downloadPassword !== null) {
            $job['download_passwords'] = [$downloadPassword];
        }

        if (is_string($input) && preg_match('#^https?://#i', $input) === 1) {
            $job['process'] = true;
            $job['input'] = [['type' => 'remote', 'source' => $input]];

            return $this->jobs->create($job);
        }

        $job['process'] = false;
        $created = $this->jobs->create($job);
        $this->jobs->upload($created, $input, $filename);

        return $this->jobs->start($created->id);
    }
}
