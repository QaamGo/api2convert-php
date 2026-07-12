<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Enum\CloudProvider;
use Api2Convert\Input\CloudInput;
use Api2Convert\Model\Job;
use Api2Convert\Model\OutputTarget;
use Api2Convert\Tests\TestCase;

/**
 * Cloud-connector parity fixtures 1 (create-payload serialization) and 2 (read hydration),
 * plus the unit behaviour of the new cloud types. The JSON shapes and assertions mirror the
 * canonical fixtures shared across every SDK.
 */
final class CloudConnectorTest extends TestCase
{
    /** The exact input descriptor fixture 1 expects the SDK to serialize. */
    private const EXPECTED_INPUT = [
        'type' => 'cloud',
        'source' => 'amazons3',
        'parameters' => ['bucket' => 'my-bucket', 'file' => 'in/photo.png'],
        'credentials' => ['accesskeyid' => 'AKIA_TEST', 'secretaccesskey' => 'SECRET_TEST'],
    ];

    /** The exact output_target descriptor fixture 1 expects — note: no `status` key. */
    private const EXPECTED_OUTPUT_TARGET = [
        'type' => 'ftp',
        'parameters' => ['host' => 'ftp.example.com', 'file' => '/out/photo.jpg'],
        'credentials' => ['username' => 'u', 'password' => 'p'],
    ];

    // ---- Fixture 1: create-payload (what convert() serializes) ------------------------------

    public function testFixture1ConvertSerializesCloudInputAndOutputTarget(): void
    {
        // create → started job; wait() polls once to a completed job with no local output.
        $this->http->addResponse($this->jsonResponse(201, ['id' => 'job-1', 'status' => ['code' => 'incomplete']]));
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'job-1', 'status' => ['code' => 'completed']]));

        $input = CloudInput::amazonS3(
            bucket: 'my-bucket',
            file: 'in/photo.png',
            accesskeyid: 'AKIA_TEST',
            secretaccesskey: 'SECRET_TEST',
        );
        $target = new OutputTarget(
            type: 'ftp',
            parameters: ['host' => 'ftp.example.com', 'file' => '/out/photo.jpg'],
            credentials: ['username' => 'u', 'password' => 'p'],
        );

        $this->client()->convert($input, 'jpg', outputTargets: [$target]);

        $body = $this->bodyOf($this->requestAt(0));

        // 1) a cloud input is a started job (like a remote URL), not staged/uploaded.
        self::assertTrue($body['process']);

        // 2) input[0] carries the flat/lowercase keys exactly as the factory emits them.
        self::assertSame([self::EXPECTED_INPUT], $body['input']);

        // 3) conversion[0].output_target[0] serializes {type,parameters,credentials} and NO status.
        self::assertSame([self::EXPECTED_OUTPUT_TARGET], $body['conversion'][0]['output_target']);
        self::assertArrayNotHasKey('status', $body['conversion'][0]['output_target'][0]);

        // output targets never leak into the conversion options map.
        self::assertArrayNotHasKey('options', $body['conversion'][0]);
    }

    public function testFixture1RawCreatePathProducesByteIdenticalOutputTarget(): void
    {
        $this->http->addResponse($this->jsonResponse(201, ['id' => 'job-1', 'status' => ['code' => 'completed']]));

        $this->client()->jobs()->create([
            'process' => true,
            'input' => [CloudInput::amazonS3('my-bucket', 'in/photo.png', 'AKIA_TEST', 'SECRET_TEST')->toArray()],
            'conversion' => [[
                'target' => 'jpg',
                'output_target' => [OutputTarget::of(
                    CloudProvider::Ftp,
                    ['host' => 'ftp.example.com', 'file' => '/out/photo.jpg'],
                    ['username' => 'u', 'password' => 'p'],
                )->toArray()],
            ]],
        ]);

        $body = $this->bodyOf($this->requestAt(0));

        // Both the convert() outputTargets control and the raw create map yield the same bytes.
        self::assertSame([self::EXPECTED_INPUT], $body['input']);
        self::assertSame([self::EXPECTED_OUTPUT_TARGET], $body['conversion'][0]['output_target']);
    }

    public function testAddInputAcceptsCloudInputBuilder(): void
    {
        $this->http->addResponse($this->jsonResponse(200, ['id' => 'in-1', 'type' => 'cloud', 'source' => 'ftp']));

        $this->client()->jobs()->addInput('job-1', CloudInput::ftp('ftp.example.com', 'in/a.png', 'u', 'p'));

        $body = $this->bodyOf($this->requestAt(0));
        self::assertSame('cloud', $body['type']);
        self::assertSame('ftp', $body['source']);
        self::assertSame(['host' => 'ftp.example.com', 'file' => 'in/a.png'], $body['parameters']);
        self::assertSame(['username' => 'u', 'password' => 'p'], $body['credentials']);
    }

    // ---- Fixture 2: read hydration (a GET /jobs/{id} response) ------------------------------

    public function testFixture2HydratesCloudInputAndOutputTarget(): void
    {
        $job = Job::fromArray([
            'id' => 'job-1',
            'status' => ['code' => 'completed'],
            'input' => [[
                'id' => 'in-1',
                'type' => 'cloud',
                'source' => 'amazons3',
                'status' => 'ready',
                'parameters' => ['bucket' => 'my-bucket', 'file' => 'in/photo.png'],
                'credentials' => [],
            ]],
            'conversion' => [[
                'id' => 'c-1',
                'target' => 'jpg',
                'output_target' => [[
                    'type' => 'ftp',
                    'parameters' => ['host' => 'ftp.example.com', 'file' => '/out/photo.jpg'],
                    'credentials' => [],
                    'status' => 'uploading',
                ]],
            ]],
        ]);

        // 1) input source is a RAW string; parameters surface.
        $in = $job->input[0];
        self::assertSame('amazons3', $in->source);
        self::assertSame('ready', $in->status);
        self::assertSame(['bucket' => 'my-bucket', 'file' => 'in/photo.png'], $in->parameters);

        // 2) output target status/parameters/type surface.
        $out = $job->conversion[0]->outputTargets[0];
        self::assertSame('ftp', $out->type);
        self::assertSame('uploading', $out->status);
        self::assertSame(['host' => 'ftp.example.com', 'file' => '/out/photo.jpg'], $out->parameters);

        // 3) credentials are never surfaced (the API returns them empty; the SDK does not hydrate).
        self::assertSame([], $out->credentials);
    }

    public function testFixture2UnknownProviderRoundTripsUntyped(): void
    {
        $job = Job::fromArray([
            'id' => 'job-1',
            'status' => ['code' => 'completed'],
            'input' => [['id' => 'in-1', 'type' => 'cloud', 'source' => 'r2', 'status' => 'ready']],
            'conversion' => [[
                'target' => 'jpg',
                'output_target' => [['type' => 'r2', 'status' => 'waiting']],
            ]],
        ]);

        // An unknown provider string hydrates without any enum parse throwing.
        self::assertSame('r2', $job->input[0]->source);
        self::assertSame('r2', $job->conversion[0]->outputTargets[0]->type);
        self::assertSame('waiting', $job->conversion[0]->outputTargets[0]->status);
    }

    // ---- Unit: the new value types ---------------------------------------------------------

    public function testCloudProviderVocabulary(): void
    {
        self::assertSame(
            ['amazons3', 'azure', 'ftp', 'gdrive', 'googlecloud', 'youtube'],
            array_map(static fn (CloudProvider $p): string => $p->value, CloudProvider::cases()),
        );
        // Tolerant hydration never throws on an unknown provider.
        self::assertNull(CloudProvider::tryFrom('r2'));
    }

    public function testPerProviderConstructorsCarryRequiredKeysVerbatim(): void
    {
        self::assertSame(
            ['type' => 'cloud', 'source' => 'azure',
             'parameters' => ['container' => 'c', 'file' => 'f'],
             'credentials' => ['accountname' => 'n', 'accountkey' => 'k']],
            CloudInput::azure('c', 'f', 'n', 'k')->toArray(),
        );
        self::assertSame(
            ['type' => 'cloud', 'source' => 'googlecloud',
             'parameters' => ['projectid' => 'p', 'bucket' => 'b', 'file' => 'f'],
             'credentials' => ['keyfile' => 'kf']],
            CloudInput::googleCloud('p', 'b', 'f', 'kf')->toArray(),
        );
    }

    public function testGenericEscapeHatchCarriesForwardCompatKeys(): void
    {
        $in = CloudInput::amazonS3('b', 'f', 'id', 'sec', ['region' => 'eu'], ['sessiontoken' => 't']);

        self::assertSame(['bucket' => 'b', 'file' => 'f', 'region' => 'eu'], $in->parameters);
        self::assertSame(['accesskeyid' => 'id', 'secretaccesskey' => 'sec', 'sessiontoken' => 't'], $in->credentials);
    }

    public function testOutputTargetOmitsStatusOnSerializeButHydratesItOnRead(): void
    {
        $created = new OutputTarget('ftp', ['host' => 'h'], ['username' => 'u'], status: 'completed');
        self::assertArrayNotHasKey('status', $created->toArray());

        $read = OutputTarget::fromArray(['type' => 'ftp', 'parameters' => ['host' => 'h'], 'status' => 'completed']);
        self::assertSame('completed', $read->status);
        self::assertSame([], $read->credentials);
    }
}
