<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * A one-shot listener on loopback, spawned as a child process, that reports back
 * everything it saw on the wire.
 *
 * A child process rather than a stubbed handler because the whole point is to
 * exercise the real transport: `Http::fake` short-circuits inside Laravel's stub
 * handler, upstream of Guzzle's `CurlFactory`, so under a fake there is no
 * socket, no header the target could have received wrong, no timeout to honour
 * and no proxy machinery at all.
 *
 * It serves two callers with one socket implementation, which is why it lives
 * here rather than inside either test:
 *
 *  - `Tests\Feature\Monitoring\LocalProbeEngineTest` drives the four `PROXY_*`
 *    modes, the two shapes a forward proxy carries plus the two ways it refuses
 *    to carry one.
 *  - `Tests\Feature\Billing\WebhookSeamTest` drives {@see self::SERVES_HTTP}, a
 *    plain HTTP server that records the method, path, headers and body it was
 *    sent and can be told to stall past the caller's deadline.
 *
 * Every mode is ONE connection and then done. Read the report with
 * {@see self::report()}, which drains the child, reaps it and cleans up its
 * temporary files.
 */
final class LoopbackHttpServer
{
    /**
     * The listener answers the absolute-form request line itself, which is how a
     * forward proxy carries a CLEARTEXT target. No tunnel is involved.
     */
    public const string PROXY_SERVES_ABSOLUTE_FORM = 'absolute-form';

    /**
     * The listener opens the tunnel and then IS the origin behind it: it
     * terminates the TLS the client negotiates through the tunnel and answers
     * with the origin's own response. This is the production shape.
     */
    public const string PROXY_TUNNELS = 'tunnel';

    /**
     * The listener answers `200 Connection established` and then closes, so the
     * target receives nothing at all. THE DEFECT `LocalProbeEngineTest` exists
     * to pin.
     */
    public const string PROXY_ESTABLISHES_AND_CLOSES = 'establish-and-close';

    /**
     * The listener refuses to open the tunnel, with a status of its own.
     */
    public const string PROXY_REFUSES_TUNNEL = 'refuse-tunnel';

    /**
     * The listener is a plain HTTP server: it records the request line, the
     * headers and the whole body, optionally stalls, then answers with the
     * status, headers and body it was configured with.
     */
    public const string SERVES_HTTP = 'http';

    /**
     * The child process handle.
     *
     * @var resource
     */
    protected $process;

    /**
     * The child's stdout (1) and stderr (2).
     *
     * @var array<int, resource>
     */
    protected array $pipes;

    /**
     * The drained report, so a second {@see self::report()} call is safe and the
     * destructor knows the child is already reaped.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $report = null;

    /**
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     * @param  int  $port  The loopback port the child bound.
     * @param  string  $script  The temporary script file the child runs.
     * @param  string  $certificate  The temporary certificate file, or '' when the mode needs none.
     */
    protected function __construct(
        $process,
        array $pipes,
        protected readonly int $port,
        protected readonly string $script,
        protected readonly string $certificate,
    ) {
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /**
     * Spawn a listener that behaves like a forward proxy in ONE of four ways.
     *
     * The four modes exist because the two shapes a forward proxy carries are
     * not the same code path in curl, and only the second one is production: a
     * cleartext target travels as an absolute-form request line, and an HTTPS
     * target travels as a CONNECT tunnel with TLS negotiated end to end INSIDE
     * it. The two failure modes are what a proxy does when it will not carry
     * one.
     *
     * @param  string  $mode  One of the `PROXY_*` constants.
     * @param  int  $refusalStatus  The status the tunnel is refused with, for
     *                              {@see self::PROXY_REFUSES_TUNNEL} only.
     */
    public static function proxy(
        string $mode = self::PROXY_SERVES_ABSOLUTE_FORM,
        int $refusalStatus = 0,
    ): self {
        return self::spawn([
            'mode' => $mode,
            'certificate' => $mode === self::PROXY_TUNNELS ? self::selfSignedCertificate() : '',
            'refusal_status' => $refusalStatus,
            'response' => self::responseConfiguration(200, '', [], 0),
        ]);
    }

    /**
     * Spawn a plain HTTP server that answers one request with the given
     * response, after recording what it received.
     *
     * @param  int  $status  The status line the listener answers with.
     * @param  string  $body  The response body, delivered with its own `Content-Length`.
     * @param  array<string, string>  $headers  Response headers.
     * @param  int  $delayMs  How long to stall AFTER reading the request and
     *                        BEFORE answering. This is the arm a timeout
     *                        assertion needs: set it past the caller's deadline
     *                        and the caller must give up rather than wait.
     */
    public static function serving(
        int $status = 200,
        string $body = '',
        array $headers = ['Content-Type' => 'application/json'],
        int $delayMs = 0,
    ): self {
        return self::spawn([
            'mode' => self::SERVES_HTTP,
            'certificate' => '',
            'refusal_status' => 0,
            'response' => self::responseConfiguration($status, $body, $headers, $delayMs),
        ]);
    }

    /**
     * The loopback port the child bound, chosen by the kernel.
     */
    public function port(): int
    {
        return $this->port;
    }

    /**
     * An absolute cleartext URL addressing this listener.
     *
     * @param  string  $path  The path (and query) to append, leading slash included.
     */
    public function url(string $path = '/'): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    /**
     * Drain the listener's report and reap it.
     *
     * The proxy modes report `connect`, `request`, `declared` and `written`; the
     * HTTP mode reports `method`, `path`, `headers`, `body` and `request` (the
     * verbatim head). Every key is present in every mode, so a caller can only
     * read a mode's own keys wrong, never miss them.
     *
     * @return array{connect: string, request: string, declared: int, written: int, method: string, path: string, headers: array<string, string>, body: string}
     */
    public function report(): array
    {
        if ($this->report !== null) {
            return $this->report;
        }

        $report = json_decode((string) fgets($this->pipes[1]), true);
        $stderr = stream_get_contents($this->pipes[2]);

        $this->release();

        Assert::assertIsArray($report, "The loopback listener reported nothing. stderr: {$stderr}");

        return $this->report = $report;
    }

    /**
     * Reap a listener whose report was never drained, so a failing test leaves
     * behind neither a child process holding a socket nor a temporary file.
     */
    public function __destruct()
    {
        if ($this->report === null) {
            proc_terminate($this->process);
            $this->release();
        }
    }

    /**
     * The response half of the child's configuration.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string, delay_ms: int}
     */
    protected static function responseConfiguration(int $status, string $body, array $headers, int $delayMs): array
    {
        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'delay_ms' => $delayMs,
        ];
    }

    /**
     * Write the child script, start it, and wait for it to announce its port.
     *
     * @param  array<string, mixed>  $configuration
     */
    protected static function spawn(array $configuration): self
    {
        if (! function_exists('proc_open')) {
            Assert::markTestSkipped('proc_open is disabled, so the live wire cannot be exercised here.');
        }

        $script = tempnam(sys_get_temp_dir(), 'uptizm-loopback-server-').'.php';

        file_put_contents($script, self::childScript());

        $process = proc_open(
            [PHP_BINARY, $script, (string) json_encode($configuration)],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            @unlink($script);
            Assert::markTestSkipped('The one-shot listener process could not be started.');
        }

        $port = (int) trim((string) fgets($pipes[1]));

        if ($port <= 0) {
            proc_terminate($process);
            @unlink($script);
            Assert::markTestSkipped('The one-shot listener could not bind a loopback port.');
        }

        return new self($process, $pipes, $port, $script, $configuration['certificate']);
    }

    /**
     * Close the child's pipes, reap it, and remove its temporary files.
     */
    protected function release(): void
    {
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        proc_close($this->process);

        @unlink($this->script);

        if ($this->certificate !== '') {
            @unlink($this->certificate);
        }
    }

    /**
     * A throwaway self-signed certificate for the tunnelled origin, generated in
     * process so no test needs a fixture file and no network.
     *
     * The client trusts it through `verify => false` rather than through a trust
     * store, which is deliberate: what the tunnel tests measure is whose
     * RESPONSE was captured, and a certificate chain is not part of that
     * question. The handshake itself still has to succeed, which is what proves
     * the tunnel is real rather than the proxy's greeting.
     */
    protected static function selfSignedCertificate(): string
    {
        if (! extension_loaded('openssl')) {
            Assert::markTestSkipped('The openssl extension is absent, so a tunnelled origin cannot be served.');
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $signed = $key === false
            ? false
            : openssl_csr_sign(
                openssl_csr_new(['commonName' => 'uptizm-probe.invalid'], $key, ['digest_alg' => 'sha256']),
                null,
                $key,
                1,
                ['digest_alg' => 'sha256'],
            );

        if ($signed === false) {
            Assert::markTestSkipped('A self-signed certificate could not be generated: '.openssl_error_string());
        }

        openssl_x509_export($signed, $certificate);
        openssl_pkey_export($key, $privateKey);

        $path = tempnam(sys_get_temp_dir(), 'uptizm-loopback-origin-').'.pem';
        file_put_contents($path, $certificate.$privateKey);

        return $path;
    }

    /**
     * The listener itself, run in a child process.
     *
     * It announces its port on the first line of stdout and its report as JSON
     * on the second, which is the handshake {@see self::spawn()} and
     * {@see self::report()} read.
     */
    protected static function childScript(): string
    {
        return <<<'PHP'
            <?php
            // A one-shot loopback listener. argv[1] is the JSON configuration:
            // mode, certificate, refusal_status and the response to answer with.
            $configuration = json_decode($argv[1], true);
            $mode = $configuration['mode'];
            $certificate = $configuration['certificate'];
            $refusalStatus = (int) $configuration['refusal_status'];
            $answer = $configuration['response'];

            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if ($server === false) {
                fwrite(STDERR, "listen failed: {$error}\n");
                exit(1);
            }
            $name = stream_socket_get_name($server, false);
            echo substr($name, strrpos($name, ':') + 1), "\n";

            // One request head, up to the blank line, off whichever stream is handed in:
            // the cleartext socket for the proxy hop, the encrypted one for the origin.
            $readHead = static function ($stream): string {
                $head = '';
                while (($line = fgets($stream, 8192)) !== false) {
                    $head .= $line;
                    if ($line === "\r\n") {
                        break;
                    }
                }

                return $head;
            };

            // The origin's own answer, promising 4 MB nothing may download.
            $serveOrigin = static function ($stream) use (&$declared, &$written): void {
                $body = str_repeat('x', 4194304);
                $declared = strlen($body);
                fwrite(
                    $stream,
                    "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\n"
                    ."X-Origin: loopback\r\nContent-Length: {$declared}\r\n\r\n",
                );
                $push = @fwrite($stream, $body);
                $written = $push === false ? 0 : $push;
            };

            $client = stream_socket_accept($server, 10);
            if ($client === false) {
                fwrite(STDERR, "no connection arrived\n");
                exit(1);
            }
            stream_set_timeout($client, 10);

            $connect = '';
            $request = '';
            $declared = 0;
            $written = 0;
            $method = '';
            $path = '';
            $headers = [];
            $body = '';

            $head = $readHead($client);

            if ($mode === 'http') {
                // An HTTP server: record the request as the far end actually sent
                // it, then answer. The head is kept verbatim as `request` too, so a
                // caller can assert on a raw line nothing here parsed.
                $request = $head;
                $lines = explode("\r\n", $head);
                $requestLine = explode(' ', (string) array_shift($lines));
                $method = $requestLine[0] ?? '';
                $path = $requestLine[1] ?? '';

                foreach ($lines as $line) {
                    if (! str_contains($line, ':')) {
                        continue;
                    }

                    [$field, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($field))] = trim($value);
                }

                // The body is read by declared length rather than to EOF: the
                // client keeps the connection open waiting for our answer, so
                // reading to EOF would deadlock until the stream timeout.
                $length = (int) ($headers['content-length'] ?? 0);
                while (strlen($body) < $length) {
                    $chunk = fread($client, min(8192, $length - strlen($body)));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $body .= $chunk;
                }

                // The stall arm: the request is already recorded, so a caller that
                // gives up here still gets a full report of what it sent.
                if ($answer['delay_ms'] > 0) {
                    usleep($answer['delay_ms'] * 1000);
                }

                $out = "HTTP/1.1 {$answer['status']} Loopback\r\n";
                foreach ($answer['headers'] as $field => $value) {
                    $out .= "{$field}: {$value}\r\n";
                }
                $out .= 'Content-Length: '.strlen($answer['body'])."\r\nConnection: close\r\n\r\n".$answer['body'];

                // Unchecked: a caller that already timed out has gone, and a write
                // to a socket it closed is expected rather than a listener failure.
                @fwrite($client, $out);
                @fclose($client);
            } elseif (! str_starts_with($head, 'CONNECT ')) {
                // An absolute-form request line: answered directly, no tunnel.
                $request = $head;
                $serveOrigin($client);
                fclose($client);
            } elseif ($mode === 'refuse-tunnel') {
                $connect = $head;
                fwrite($client, "HTTP/1.1 {$refusalStatus} Nope\r\nProxy-Authenticate: Basic realm=\"one-shot\"\r\nContent-Length: 0\r\n\r\n");
                fclose($client);
            } else {
                $connect = $head;
                fwrite($client, "HTTP/1.1 200 Connection established\r\n\r\n");

                if ($mode === 'establish-and-close') {
                    // The greeting and nothing else: the target is never contacted.
                    fclose($client);
                } else {
                    // The proxy IS the origin from here: it terminates the TLS the
                    // probe negotiates inside the tunnel and answers over it.
                    stream_context_set_options($client, ['ssl' => ['local_cert' => $certificate]]);
                    $secured = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);

                    if ($secured !== true) {
                        fwrite(STDERR, 'the tunnelled TLS handshake failed: '.var_export($secured, true)."\n");
                    } else {
                        $request = $readHead($client);
                        $serveOrigin($client);
                    }

                    @fclose($client);
                }
            }

            echo json_encode([
                'connect' => $connect,
                'request' => $request,
                'declared' => $declared,
                'written' => $written,
                'method' => $method,
                'path' => $path,
                'headers' => $headers,
                'body' => $body,
            ]), "\n";
            PHP;
    }
}
