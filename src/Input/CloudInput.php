<?php

declare(strict_types=1);

namespace Api2Convert\Input;

use Api2Convert\Enum\CloudProvider;
use Api2Convert\Enum\InputType;
use Api2Convert\Support\Redactor;
use Stringable;

/**
 * A cloud-storage input descriptor: `{ type:"cloud", source:<provider>, parameters, credentials }`.
 *
 * Hand it to `$client->convert()` / `convertAsync()` as the input, or to
 * `$client->jobs()->addInput($jobId, $cloudInput)`; either way it emits the wire descriptor via
 * {@see toArray()}. Like a remote URL, a cloud input is a **started** job (`process => true`), not
 * a staged upload.
 *
 * The per-provider named constructors carry each provider's required keys **verbatim** — flat and
 * lowercase, exactly as the API expects (`accesskeyid`, not `access_key_id`). The required keys are
 * constructor arguments (structural correctness), **not** a runtime gate: the builder never rejects
 * a descriptor the permissive, asynchronously-validating server would accept. Optional and
 * forward-compat keys go through the trailing `$parameters` / `$credentials` maps, or the generic
 * {@see of()} escape hatch.
 *
 * Google Drive *input* uses the `gdrive_picker` input type (the generic `addInput` raw-map path this
 * wave); `gdrive`/`youtube` are output-only.
 *
 * `credentials` ride in the plaintext body, so {@see __toString()} masks the **whole** credentials
 * object to `[REDACTED]` and any sensitive `parameters` leaf (see {@see Redactor}).
 */
final class CloudInput implements Stringable
{
    /**
     * @param array<string, mixed> $parameters  Non-secret locator keys (`bucket`, `file`, `host`, …).
     * @param array<string, mixed> $credentials Secret keys (access keys, passwords, tokens).
     */
    public function __construct(
        public readonly string $source,
        public readonly array $parameters = [],
        public readonly array $credentials = [],
    ) {
    }

    /**
     * Generic escape hatch: any provider (typed or a forward-compat string) with free-form maps.
     *
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $credentials
     */
    public static function of(CloudProvider|string $source, array $parameters = [], array $credentials = []): self
    {
        return new self(
            $source instanceof CloudProvider ? $source->value : $source,
            $parameters,
            $credentials,
        );
    }

    /**
     * Import from Amazon S3.
     *
     * @param array<string, mixed> $parameters  Extra/forward-compat locator keys, merged in.
     * @param array<string, mixed> $credentials Extra/forward-compat secret keys, merged in.
     */
    public static function amazonS3(
        string $bucket,
        string $file,
        string $accesskeyid,
        string $secretaccesskey,
        array $parameters = [],
        array $credentials = [],
    ): self {
        return new self(
            CloudProvider::AmazonS3->value,
            ['bucket' => $bucket, 'file' => $file, ...$parameters],
            ['accesskeyid' => $accesskeyid, 'secretaccesskey' => $secretaccesskey, ...$credentials],
        );
    }

    /**
     * Import from Azure Blob Storage.
     *
     * @param array<string, mixed> $parameters  Extra/forward-compat locator keys, merged in.
     * @param array<string, mixed> $credentials Extra/forward-compat secret keys, merged in.
     */
    public static function azure(
        string $container,
        string $file,
        string $accountname,
        string $accountkey,
        array $parameters = [],
        array $credentials = [],
    ): self {
        return new self(
            CloudProvider::Azure->value,
            ['container' => $container, 'file' => $file, ...$parameters],
            ['accountname' => $accountname, 'accountkey' => $accountkey, ...$credentials],
        );
    }

    /**
     * Import from an FTP server.
     *
     * @param array<string, mixed> $parameters  Extra/forward-compat locator keys, merged in.
     * @param array<string, mixed> $credentials Extra/forward-compat secret keys, merged in.
     */
    public static function ftp(
        string $host,
        string $file,
        string $username,
        string $password,
        array $parameters = [],
        array $credentials = [],
    ): self {
        return new self(
            CloudProvider::Ftp->value,
            ['host' => $host, 'file' => $file, ...$parameters],
            ['username' => $username, 'password' => $password, ...$credentials],
        );
    }

    /**
     * Import from Google Cloud Storage.
     *
     * @param array<string, mixed> $parameters  Extra/forward-compat locator keys, merged in.
     * @param array<string, mixed> $credentials Extra/forward-compat secret keys, merged in.
     */
    public static function googleCloud(
        string $projectid,
        string $bucket,
        string $file,
        string $keyfile,
        array $parameters = [],
        array $credentials = [],
    ): self {
        return new self(
            CloudProvider::GoogleCloud->value,
            ['projectid' => $projectid, 'bucket' => $bucket, 'file' => $file, ...$parameters],
            ['keyfile' => $keyfile, ...$credentials],
        );
    }

    /**
     * The wire descriptor sent to `POST /jobs` (inline `input`) or `POST /jobs/{id}/input`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => InputType::Cloud->value,
            'source' => $this->source,
            'parameters' => $this->parameters,
            'credentials' => $this->credentials,
        ];
    }

    /**
     * Human-readable form with credentials masked — safe to log. The whole `credentials` object
     * renders as `[REDACTED]`; sensitive `parameters` leaves are masked too.
     */
    public function __toString(): string
    {
        return sprintf(
            'CloudInput(type=cloud, source=%s, parameters=%s, credentials=%s)',
            $this->source,
            (string) json_encode(Redactor::parameters($this->parameters)),
            Redactor::MARKER,
        );
    }
}
