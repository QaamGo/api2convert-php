<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Api2Convert;
use Api2Convert\Model\Job;
use Api2Convert\Model\OutputFile;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * The per-request timeout must bound only the connect/handshake phase, never the
 * whole streamed transfer. A whole-request timeout (Guzzle `timeout` → cURL
 * `CURLOPT_TIMEOUT`) caps connect + send + receive together, so it aborts a slow
 * upload while its body is still being sent, and a slow download while its body is
 * still arriving — even though the connection was established promptly.
 *
 * These run the REAL Guzzle/cURL client (no injected mock) against a loopback
 * server that connects instantly but responds only after a delay longer than the
 * (floored to 1s) timeout. With the old whole-request timeout the transfer is
 * aborted; with the connect-only budget it completes. This is the differential
 * proof for Track B H2.
 */
final class StreamingTimeoutTest extends TestCase
{
    /** @var list<resource> */
    private array $procs = [];

    /** @var list<string> */
    private array $scripts = [];

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled; cannot start a loopback server.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            @proc_terminate($proc);
            @proc_close($proc);
        }
        foreach ($this->scripts as $script) {
            @unlink($script);
        }
    }

    public function testSlowDownloadIsNotCappedByThePerRequestTimeout(): void
    {
        // A GET whose response body arrives only after the server's delay must still
        // succeed — the streamed download is bounded by the caller, not the timeout.
        $port = $this->startSlowServer(2000, 'PDF-BYTES');
        $client = new Api2Convert('k', ['timeout' => 1, 'maxRetries' => 0]);
        $output = new OutputFile(id: 'o', uri: "http://127.0.0.1:{$port}/file", filename: 'result.pdf');

        self::assertSame('PDF-BYTES', $client->download($output)->contents());
    }

    public function testSlowUploadIsNotCappedByThePerRequestTimeout(): void
    {
        // A streamed upload transmits its whole body before the response is received,
        // so a whole-request timeout would abort a large/slow upload. The server
        // responds well past the timeout, yet the upload must still succeed.
        $port = $this->startSlowServer(2000, '{"id":"in-1","type":"upload"}');
        $client = new Api2Convert('k', ['timeout' => 1, 'maxRetries' => 0]);
        $job = Job::fromArray([
            'id' => 'job-9',
            'server' => "http://127.0.0.1:{$port}",
            'token' => 'tok-abc',
            'status' => ['code' => 'incomplete'],
        ]);

        $input = $client->jobs()->upload($job, Utils::streamFor('hello world'), 'hello.txt');

        self::assertSame('in-1', $input->id);
    }

    /**
     * Launch a loopback HTTP server that accepts one connection, waits $delayMs, then
     * replies 200 with $body. Returns the port it bound to.
     */
    private function startSlowServer(int $delayMs, string $body): int
    {
        $script = tempnam(sys_get_temp_dir(), 'a2c-slow-server-') . '.php';
        file_put_contents($script, self::SERVER_SCRIPT);
        $this->scripts[] = $script;

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, $script, (string) $delayMs, base64_encode($body)],
            $descriptors,
            $pipes,
        );
        if (!is_resource($proc)) {
            self::markTestSkipped('Could not spawn a loopback server process.');
        }
        $this->procs[] = $proc;

        // The child prints the port it bound to as its first line once it is listening.
        $line = fgets($pipes[1]);
        if ($line === false || !str_starts_with($line, 'PORT=')) {
            self::fail('Loopback server did not report a port: ' . var_export($line, true));
        }

        return (int) substr(trim($line), strlen('PORT='));
    }

    private const SERVER_SCRIPT = <<<'PHP'
        <?php
        // argv[1] = response delay in ms; argv[2] = base64-encoded response body.
        $delayMs = (int) ($argv[1] ?? 0);
        $body = base64_decode($argv[2] ?? '', true) ?: '';

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            fwrite(STDERR, "listen failed: {$errstr}\n");
            exit(1);
        }
        $name = stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fwrite(STDOUT, "PORT={$port}\n");
        fflush(STDOUT);

        $conn = stream_socket_accept($server, 30);
        if ($conn === false) {
            exit(1);
        }
        stream_set_timeout($conn, 10);

        // Read the request head (a tiny request body rides in the kernel buffer and
        // needs no draining before we respond).
        $data = '';
        while (strpos($data, "\r\n\r\n") === false) {
            $chunk = fread($conn, 8192);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $data .= $chunk;
        }

        usleep($delayMs * 1000);

        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
        fwrite($conn, $response);
        fclose($conn);
        fclose($server);
        PHP;
}
