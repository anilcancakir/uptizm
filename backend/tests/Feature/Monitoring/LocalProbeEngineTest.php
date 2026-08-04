<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Proxy;
use App\Models\ProxySource;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\Monitoring\LocalProbeEngine;
use App\Services\Proxy\ProxyPool;
use App\Support\Services\SystemTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Both transports write into ONE `monitor_checks` table and one public page
 * reads it, so a divergence between this engine and `regional-probe.ts` does not
 * fail a build: it shows up as a status flip on a page that publishes how it
 * measures. These tests pin the rules that flip it.
 *
 * The three-way status rule and the no-redirect rule are each PUBLISHED claims
 * (`resources/legal/bot.en.md:24-28`), so each is asserted here rather than
 * assumed from the worker's TypeScript.
 *
 * ## Why one test spawns a real server and the rest do not
 *
 * `Http::fake` short-circuits inside Laravel's stub handler, upstream of Guzzle's
 * `CurlFactory`. So under a fake the `on_headers` callback never fires,
 * `handlerStats()` is empty, and the request returns a plain Response. That is
 * fine for the status rules, the redirect policy and the transfer options, all of
 * which are decided before the wire.
 *
 * It is USELESS for the mechanism this engine is built on: a successful probe
 * arrives as an EXCEPTION, because the engine throws a sentinel out of
 * `on_headers` to abort the body. A fake-based assertion of that path would pass
 * with the entire mechanism deleted. So
 * {@see self::test_a_probe_whose_body_the_engine_aborted_is_still_a_successful_reading()}
 * drives a real one-shot HTTP proxy on loopback, and it is the only test here
 * that proves the header capture, the abort and curl's own timing.
 */
class LocalProbeEngineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Guzzle transfer options of every request the engine issued.
     *
     * `Http::recorded()` keeps the request and the response but NOT the options,
     * and the proxy, its credentials and the redirect policy all live there. The
     * stub callback is the only place Laravel hands them over.
     *
     * @var list<array<string, mixed>>
     */
    protected array $seenOptions = [];

    protected int $proxySequence = 0;

    public function test_an_exact_status_code_match_is_up(): void
    {
        $monitor = $this->systemMonitor(['expected_status_code' => 200]);
        $this->makeProxy('us-east');
        $this->fakeTarget(200);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Up, $reading->status);
        $this->assertSame(200, $reading->statusCode);
        $this->assertSame((string) $monitor->id, $reading->monitorId);
        $this->assertSame('us-east', $reading->region);
        $this->assertFalse($reading->probeRefused);
        $this->assertNull($reading->errorMessage);
    }

    public function test_a_non_matching_2xx_is_degraded_rather_than_down(): void
    {
        // The load-bearing arm of the three-way rule: a 200 against an expected
        // 204 is a service answering differently, not a service that is down. A
        // two-way rule pages someone for a working target.
        $monitor = $this->systemMonitor(['expected_status_code' => 204]);
        $this->makeProxy('us-east');
        $this->fakeTarget(200);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Degraded, $reading->status);
        $this->assertSame(200, $reading->statusCode);
    }

    public function test_a_server_error_is_down(): void
    {
        $monitor = $this->systemMonitor(['expected_status_code' => 200]);
        $this->makeProxy('us-east');
        $this->fakeTarget(500);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Down, $reading->status);
        $this->assertSame(500, $reading->statusCode);
    }

    public function test_a_client_error_is_down_and_is_still_a_reading(): void
    {
        // A 403 is the TARGET's answer and is recorded as one. Nothing here may
        // treat it as an exit failure; that is what keeps `bot.en.md:65-66` true.
        $monitor = $this->systemMonitor(['expected_status_code' => 200]);
        $exit = $this->makeProxy('us-east');
        $this->fakeTarget(403);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(MonitorStatus::Down, $reading->status);
        $this->assertSame(403, $reading->statusCode);
        $this->assertSame(0, $exit->fresh()->failed_attempts);
    }

    public function test_a_301_is_recorded_as_a_301_and_the_redirect_is_never_followed(): void
    {
        $monitor = $this->systemMonitor([
            'url' => 'https://example.com/health',
            'expected_status_code' => 200,
        ]);
        $this->makeProxy('us-east');

        Http::fake(function (Request $request, array $options) {
            $this->seenOptions[] = $options;

            return $request->url() === 'https://example.com/health'
                ? Http::response('', 301, [
                    'Location' => 'https://example.com/moved',
                    'Content-Type' => 'text/html',
                ])
                : Http::response('', 200, ['Content-Type' => 'text/html']);
        });

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        // Deleting `allow_redirects => false` reddens BOTH assertions: Guzzle's
        // RedirectMiddleware is in the stack even under a fake, so it would
        // re-issue the request and the reading would become a 200.
        $this->assertSame(301, $reading->statusCode);
        Http::assertSentCount(1);

        // A 3xx that is not the expected code is degraded, not down: the target
        // answered.
        $this->assertSame(MonitorStatus::Degraded, $reading->status);
    }

    public function test_the_reading_carries_no_body_at_all(): void
    {
        $monitor = $this->systemMonitor(['expected_status_code' => 200]);
        $this->makeProxy('us-east');
        $this->fakeTarget(200, ['Content-Type' => 'text/html; charset=utf-8']);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        // Nothing consumes the body for a catalog monitor (`assertion_rules` is
        // null and `ai_mode` is off on all eight), and the measured payloads are
        // ~590 KB per probe against metered proxy bandwidth.
        $this->assertNull($reading->responseBodyPreview);
        $this->assertNull($reading->content);
        $this->assertFalse($reading->contentTruncated);

        // The status and the content type still come from the captured headers.
        $this->assertSame('text/html; charset=utf-8', $reading->contentType);
        $this->assertSame('text/html; charset=utf-8', $reading->responseHeaders['content-type']);
    }

    public function test_an_over_long_content_type_is_cut_at_the_column_width(): void
    {
        // The header is chosen by the monitored target and lands in a
        // `string(128)` column that PostgreSQL throws on rather than trims. The
        // engine emits the worker's wire shape so `CheckResult::fromWorkerPayload()`
        // performs that cut once, at the boundary, for both transports.
        $monitor = $this->systemMonitor(['expected_status_code' => 200]);
        $this->makeProxy('us-east');
        $this->fakeTarget(200, ['Content-Type' => 'text/'.str_repeat('x', 200)]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(128, mb_strlen((string) $reading->contentType));
    }

    public function test_the_request_egresses_through_the_pools_proxy_with_the_credentials_out_of_the_url(): void
    {
        $password = 'p@ss:word/x#1';

        $this->makeProxy('us-east', [
            'host' => '203.0.113.9',
            'port' => 8081,
            'credentials' => [
                'username' => 'exit-user',
                'password' => $password,
            ],
        ]);

        $monitor = $this->systemMonitor();
        $this->fakeTarget(200);

        $this->engine()->dispatch($monitor, 'us-east');

        $options = $this->seenOptions[0] ?? [];

        $this->assertSame('http://203.0.113.9:8081', $options['proxy'] ?? null);
        $this->assertFalse($options['allow_redirects'] ?? null);

        // Verbatim, including the `:`, the `/`, the `#` and the `@`. curl's URL
        // parser splits userinfo on the FIRST `@` and ends the authority at the
        // first raw `/`, `?` or `#`, so a provider password carrying any of them
        // corrupts a credential-bearing proxy URL silently. This option bypasses
        // that parser entirely.
        $this->assertSame(
            'exit-user:'.$password,
            $options['curl'][CURLOPT_PROXYUSERPWD] ?? null,
        );

        // And the negative: no option value anywhere carries userinfo in a URL.
        foreach (Arr::dot($options) as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '#[a-z]+://[^/\s@]+:[^/\s@]*@#i',
                $value,
                "Transfer option [{$key}] carries credentials inside a URL.",
            );
        }
    }

    /**
     * The exit is the only EVIDENCE of where a locally-produced check actually
     * egressed from, for the same reason {@see CheckResult::$colo} is evidence
     * of where the worker ran: `region` is an echo of what the pool was asked
     * for, and a proxy-derived region has exactly that problem. Without the
     * exit recorded, one blocked IP in a region looks identical to every other
     * reading from that region and cannot be diagnosed.
     */
    public function test_a_locally_produced_check_names_the_exit_it_used(): void
    {
        $this->makeProxy('us-east', [
            'host' => '203.0.113.9',
            'port' => 8081,
        ]);
        $monitor = $this->systemMonitor();
        $this->fakeTarget(200);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame('203.0.113.9:8081', $reading->exitVia);

        $this->app->make(CheckPersistenceService::class)->persist($monitor, $reading);

        $this->assertSame(
            '203.0.113.9:8081',
            MonitorCheck::query()->where('monitor_id', $monitor->id)->value('exit_via'),
        );
    }

    public function test_the_method_is_uppercased_and_an_absent_body_is_omitted(): void
    {
        $monitor = $this->systemMonitor([
            'method' => 'head',
            'request_headers' => ['X-Probe' => 'uptizm'],
            'request_body' => null,
        ]);
        $this->makeProxy('us-east');
        $this->fakeTarget(200);

        $this->engine()->dispatch($monitor, 'us-east');

        $request = Http::recorded()[0][0];

        $this->assertSame('HEAD', $request->method());
        $this->assertSame('uptizm', $request->header('X-Probe')[0]);

        // Omitted, not sent as an empty string: a zero-length body on a GET is
        // not the same request as no body at all.
        $this->assertArrayNotHasKey('body', $this->seenOptions[0]);
    }

    public function test_a_declared_request_body_travels(): void
    {
        $monitor = $this->systemMonitor([
            'method' => 'post',
            'request_body' => '{"ping":true}',
        ]);
        $this->makeProxy('us-east');
        $this->fakeTarget(200);

        $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame('{"ping":true}', Http::recorded()[0][0]->body());
    }

    public function test_each_dispatch_mints_its_own_probe_run_id_and_stamps_the_entry_time(): void
    {
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->fakeTarget(200);

        $first = $this->engine()->dispatch($monitor, 'us-east');
        $second = $this->engine()->dispatch($monitor, 'us-east');

        // The idempotency key of the whole round trip; a reused one would make
        // the processing job discard the second reading as a duplicate.
        $this->assertNotSame($first->probeRunId, $second->probeRunId);
        $this->assertSame(36, strlen($first->probeRunId));
        $this->assertLessThanOrEqual(5, abs($first->checkedAt->getTimestamp() - now()->getTimestamp()));
    }

    public function test_a_tcp_monitor_is_refused_rather_than_approximated(): void
    {
        $monitor = $this->systemMonitor([
            'type' => MonitorType::Tcp,
            'url' => 'example.com:5432',
        ]);
        $this->makeProxy('us-east');
        Http::fake();

        try {
            $this->engine()->dispatch($monitor, 'us-east');

            $this->fail('The engine produced a reading for a TCP monitor.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tcp', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * A region with no usable exit is a REFUSAL, not a thrown transport error.
     *
     * Step 8 threw here and said in its own docblock that Step 9 would turn the
     * throw into a `probeRefused` CheckResult; this is that turn. The difference is
     * not cosmetic: a refusal routes into `CheckPersistenceService::persist()`'s
     * early return, so a dark region writes no check row, moves no streak and pages
     * nobody, whereas a throw failed the queued job three times and left the reason
     * only in `failed_jobs`.
     *
     * `us-west` is a legal MonitorRegion with no entry in `config('proxy.sources')`.
     * There is still no direct-egress fallback: probing from this server would both
     * fabricate the region and delete the network boundary the proxy exists to
     * provide, which is why `Http::assertNothingSent()` closes both cases.
     */
    public function test_a_region_with_no_configured_source_is_refused_rather_than_probed_directly(): void
    {
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        Http::fake();

        $reading = $this->engine()->dispatch($monitor, 'us-west');

        $this->assertTrue(
            $reading->probeRefused,
            'A region with no proxy source must produce no verdict at all, not a down.',
        );
        $this->assertStringContainsString('us-west', (string) $reading->errorMessage);
        $this->assertStringContainsString('no usable proxy exit', (string) $reading->errorMessage);

        Http::assertNothingSent();
    }

    public function test_a_configured_region_whose_pool_is_empty_is_refused(): void
    {
        $monitor = $this->systemMonitor();
        // A source exists for `ap`, but every exit in it is out of rotation.
        $this->makeProxy('ap', ['enabled' => false]);
        Http::fake();

        $reading = $this->engine()->dispatch($monitor, 'ap');

        $this->assertTrue(
            $reading->probeRefused,
            'An exhausted pool must produce no verdict at all, not a down.',
        );
        $this->assertStringContainsString('[ap]', (string) $reading->errorMessage);
        $this->assertStringContainsString('no usable proxy exit', (string) $reading->errorMessage);

        Http::assertNothingSent();
    }

    public function test_the_timing_map_reads_curls_own_phase_boundaries(): void
    {
        // curl reports CUMULATIVE timestamps; the worker reports phase lengths
        // with the phases it cannot see set to zero. Mapping the two wrong is
        // invisible in production, so the arithmetic is pinned on a synthetic
        // stats array here rather than only through a live request.
        $engine = new class(new ProxyPool) extends LocalProbeEngine
        {
            /**
             * @param  array<string, mixed>  $stats
             * @return array<string, int>
             */
            public function timing(array $stats): array
            {
                return $this->timingFrom($stats);
            }
        };

        $timing = $engine->timing([
            'namelookup_time' => 0.010,
            'connect_time' => 0.030,
            'appconnect_time' => 0.080,
            'starttransfer_time' => 0.200,
            'total_time' => 0.250,
        ]);

        $this->assertSame([
            'dns_ms' => 10,
            'connect_ms' => 20,
            'tls_ms' => 50,
            'ttfb_ms' => 120,
            'download_ms' => 50,
        ], $timing);

        // A cleartext hop reports `appconnect_time` as 0.0 rather than as a
        // zero-length phase, so TTFB has to start from the TCP connect instead.
        $cleartext = $engine->timing([
            'namelookup_time' => 0.010,
            'connect_time' => 0.030,
            'appconnect_time' => 0.0,
            'starttransfer_time' => 0.100,
            'total_time' => 0.100,
        ]);

        $this->assertSame(0, $cleartext['tls_ms']);
        $this->assertSame(70, $cleartext['ttfb_ms']);

        // No stats at all (a faked response never crosses curl) must read as
        // zero rather than as a negative or a fabricated number.
        $this->assertSame([
            'dns_ms' => 0,
            'connect_ms' => 0,
            'tls_ms' => 0,
            'ttfb_ms' => 0,
            'download_ms' => 0,
        ], $engine->timing([]));
    }

    public function test_a_faked_response_reports_no_timing_because_none_was_measured(): void
    {
        $monitor = $this->systemMonitor();
        $this->makeProxy('us-east');
        $this->fakeTarget(200);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        // Not a defect: a stubbed response never reaches curl, so there is no
        // measurement to report and the engine reports none rather than
        // inventing one. The live test below is what proves the real path.
        $this->assertNull($reading->responseMs);
        $this->assertSame(0, $reading->timingTtfbMs);
    }

    public function test_a_probe_whose_body_the_engine_aborted_is_still_a_successful_reading(): void
    {
        // THE ONLY TEST HERE THAT TOUCHES A SOCKET, and the only one that can
        // fail if the mechanism is absent.
        //
        // The one-shot server answers with headers and a declared 4 MB body. The
        // engine captures the headers, throws its sentinel out of `on_headers`
        // and curl aborts with errno 23, which Laravel surfaces as a
        // ConnectionException. So this asserts three things a fake cannot reach:
        //
        //  1. A SUCCESS ARRIVES AS AN EXCEPTION and is still returned as a
        //     reading. Removing the capture-first check in the catch, or reading
        //     the errno before the capture, reddens this.
        //  2. The body is never pulled: the target reports it wrote ZERO body
        //     bytes. Removing the sentinel throw reddens this line, and it is
        //     wire evidence rather than an assertion about our own options.
        //  3. `handlerStats()` produced real timing, so `response_ms` is a
        //     measurement rather than a placeholder.
        $server = $this->startOneShotProxy();

        $monitor = $this->systemMonitor([
            // Cleartext, so the one-shot server can answer the absolute-form
            // request line directly instead of tunnelling a CONNECT.
            'url' => 'http://uptizm-probe.invalid/health',
            'expected_status_code' => 200,
        ]);

        $password = 'p@ss:word/x#1';

        $this->makeProxy('us-east', [
            'host' => '127.0.0.1',
            'port' => $server['port'],
            'credentials' => [
                'username' => 'exit-user',
                'password' => $password,
            ],
        ]);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $observed = $this->finishOneShotProxy($server);

        $this->assertSame(MonitorStatus::Up, $reading->status);
        $this->assertSame(200, $reading->statusCode);
        $this->assertSame('text/html; charset=utf-8', $reading->contentType);
        $this->assertNull($reading->responseBodyPreview);
        $this->assertNull($reading->content);

        $this->assertSame(0, $observed['written'], 'The engine downloaded part of the response body.');
        $this->assertGreaterThan(0, $observed['declared']);

        $this->assertNotNull($reading->responseMs);

        // The credentials reached the wire through CURLOPT_PROXYUSERPWD, with
        // every awkward character intact. This is the assertion an options-array
        // check cannot make: the stream handler would have dropped the option
        // silently and this header would be absent.
        $this->assertStringContainsString(
            'Proxy-Authorization: Basic '.base64_encode('exit-user:'.$password),
            $observed['request'],
        );
    }

    /**
     * Stub the target, recording the transfer options of each request.
     *
     * @param  array<string, string>  $headers
     */
    protected function fakeTarget(int $status, array $headers = ['Content-Type' => 'text/html']): void
    {
        Http::fake(function (Request $request, array $options) use ($status, $headers) {
            $this->seenOptions[] = $options;

            return Http::response('<html>a body nothing here may read</html>', $status, $headers);
        });
    }

    protected function engine(): LocalProbeEngine
    {
        return new LocalProbeEngine(new ProxyPool);
    }

    /**
     * A monitor owned by the one internal team this engine may probe for,
     * resolved through the production resolver because `is_system` is not
     * fillable.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function systemMonitor(array $attributes = []): Monitor
    {
        return Monitor::query()->create([
            'team_id' => SystemTeam::resolve()->id,
            'name' => 'Catalog probe',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'regions' => ['us-east'],
            'check_interval_sec' => 60,
            'timeout_sec' => 10,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
            ...$attributes,
        ]);
    }

    /**
     * A persisted, healthy exit in the given region.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeProxy(string $region, array $overrides = []): Proxy
    {
        $source = ProxySource::query()->firstOrCreate(
            ['region' => $region],
            ['kind' => 'url', 'location' => "https://example.com/{$region}.txt"],
        );

        $sequence = ++$this->proxySequence;

        return Proxy::query()->create([
            'proxy_source_id' => $source->id,
            'region' => $region,
            'host' => "203.0.113.{$sequence}",
            'port' => 8000 + $sequence,
            'credentials' => [
                'username' => 'exit-user',
                'password' => 'secret',
            ],
            'enabled' => true,
            'last_refreshed_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * Spawn a one-shot HTTP server on loopback that behaves like a forward
     * proxy: it answers the absolute-form request line directly, promises a 4 MB
     * body, and reports back how much of that body it actually managed to write.
     *
     * A child process rather than a stubbed handler because the whole point is to
     * exercise Guzzle's curl handler, and a proxied cleartext request is the one
     * shape a plain socket server can answer without tunnelling.
     *
     * @return array{process: resource, pipes: array<int, resource>, port: int}
     */
    protected function startOneShotProxy(): array
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled, so the live probe path cannot be exercised here.');
        }

        $script = tempnam(sys_get_temp_dir(), 'uptizm-probe-server-').'.php';

        file_put_contents($script, <<<'PHP'
            <?php
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if ($server === false) {
                fwrite(STDERR, "listen failed: {$error}\n");
                exit(1);
            }
            $name = stream_socket_get_name($server, false);
            echo substr($name, strrpos($name, ':') + 1), "\n";
            $client = stream_socket_accept($server, 10);
            if ($client === false) {
                fwrite(STDERR, "no connection arrived\n");
                exit(1);
            }
            $request = '';
            while (($line = fgets($client, 8192)) !== false) {
                $request .= $line;
                if ($line === "\r\n") {
                    break;
                }
            }
            $body = str_repeat('x', 4194304);
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: ".strlen($body)."\r\n\r\n");
            $written = @fwrite($client, $body);
            fclose($client);
            echo json_encode([
                'request' => $request,
                'declared' => strlen($body),
                'written' => $written === false ? 0 : $written,
            ]), "\n";
            PHP);

        $process = proc_open(
            [PHP_BINARY, $script],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            @unlink($script);
            $this->markTestSkipped('The one-shot proxy process could not be started.');
        }

        $port = (int) trim((string) fgets($pipes[1]));

        if ($port <= 0) {
            proc_terminate($process);
            @unlink($script);
            $this->markTestSkipped('The one-shot proxy could not bind a loopback port.');
        }

        return [
            'process' => $process,
            'pipes' => $pipes,
            'port' => $port,
            'script' => $script,
        ];
    }

    /**
     * Drain the one-shot server's report and reap it.
     *
     * @param  array<string, mixed>  $server
     * @return array{request: string, declared: int, written: int}
     */
    protected function finishOneShotProxy(array $server): array
    {
        $report = json_decode((string) fgets($server['pipes'][1]), true);
        $stderr = stream_get_contents($server['pipes'][2]);

        fclose($server['pipes'][1]);
        fclose($server['pipes'][2]);
        proc_close($server['process']);
        @unlink($server['script']);

        $this->assertIsArray($report, "The one-shot proxy reported nothing. stderr: {$stderr}");

        return $report;
    }
}
