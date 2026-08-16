<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\LaravelAiTriageGateway;
use App\Services\Ai\OpenRouterUpstreamRecorder;
use App\Services\Ai\TriagePayload;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Pins the instrument that makes the latency routing measurable.
 *
 * Ordering upstreams by latency is unfalsifiable without knowing WHICH upstream
 * served a call, and `laravel/ai` throws that away: its OpenRouter response
 * parser keeps `choices`, `usage` and `model` and drops everything else, so the
 * `openrouter_metadata` object never reaches an application object. The header
 * that produces it is not configurable either, since the package's client maps
 * exactly two headers (`HTTP-Referer`, `X-OpenRouter-Title`) from provider
 * config. So the instrument sits one layer lower, as a global HTTP client
 * middleware, which also means it covers all six gateways at once instead of
 * whichever one someone remembers.
 *
 * These tests run through the REAL registration in
 * {@see App\Providers\AppServiceProvider::boot()}: none of them constructs the
 * middleware except the streaming guard, which needs a body a fake cannot give.
 */
class OpenRouterUpstreamRecorderTest extends TestCase
{
    /**
     * A response body in the shape OpenRouter documents, trimmed to the fields
     * the recorder reads.
     */
    private const string METADATA_BODY = '{"model":"deepseek/deepseek-v4-flash","choices":[{"message":{"content":"ok"}}],'
        .'"openrouter_metadata":{"attempt":1,"endpoints":{"available":['
        .'{"model":"deepseek/deepseek-v4-flash","provider":"DigitalOcean","selected":false},'
        .'{"model":"deepseek/deepseek-v4-flash","provider":"CoreWeave","selected":true}'
        .'],"total":2},"strategy":"direct","region":"iad"}}';

    /**
     * Spy on the log manager with `channel()` folded back onto the spy.
     *
     * The `with()` is the load-bearing half: it pins the channel NAME at the call
     * site, so a recorder that wrote to the default channel (the production
     * silence this whole channel exists to fix) fails here rather than in the
     * one level test.
     */
    private function spyOnRoutingChannel(): void
    {
        Log::spy();

        Log::shouldReceive('channel')
            ->with(OpenRouterUpstreamRecorder::LOG_CHANNEL)
            ->once()
            ->andReturnSelf();
    }

    /**
     * A scratch directory for the level test's log files, removed after the test.
     *
     * Under the system temp dir rather than `storage/logs`, so a test can never
     * append to the log a developer or CI runner is reading.
     */
    private function temporaryLogDirectory(): string
    {
        $directory = sys_get_temp_dir().'/uptizm-ai-routing-'.Str::random(12);

        File::ensureDirectoryExists($directory);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

        return $directory;
    }

    /**
     * Put an environment value back exactly as it was, absence included.
     *
     * @param  array{env: string|null, server: string|null}  $original
     */
    private function restoreEnvValue(string $key, array $original): void
    {
        if ($original['env'] === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $original['env'];
        }

        if ($original['server'] === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $original['server'];
        }
    }

    /**
     * A complete chat completion, structured-output content included, for the one
     * test that drives a real gateway rather than a bare HTTP call.
     *
     * The recommendation has to clear
     * {@see LaravelAiTriageGateway}'s narration floor or the gateway treats this
     * body as non-conforming, retries, and throws before the assertions on the
     * REQUEST are ever reached. Keep it a sentence: production narrations run a
     * 220-character 5th percentile, so this is still well inside the short end.
     *
     * @return array<string, mixed>
     */
    private function completionBody(): array
    {
        return [
            'model' => 'deepseek/deepseek-v4-flash',
            'choices' => [
                [
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => json_encode([
                            'confirmed' => true,
                            'severity' => 'warn',
                            'confidence' => 'medium',
                            'recommendation' => 'Check the upstream dependency: latency tripled against its baseline.',
                        ]),
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
            'openrouter_metadata' => [
                'attempt' => 1,
                'endpoints' => [
                    'available' => [
                        [
                            'provider' => 'CoreWeave',
                            'selected' => true,
                        ],
                    ],
                    'total' => 1,
                ],
                'strategy' => 'direct',
                'region' => 'iad',
            ],
        ];
    }

    /**
     * Minimal owned-signal evidence: this test is about routing, not triage, so
     * the payload only has to be constructible and fenceable.
     */
    private function triagePayload(): TriagePayload
    {
        return new TriagePayload(
            monitorId: 'mon_1',
            signal: 'response_time',
            method: 'ewma',
            score: 4.2,
            severity: 'warn',
            evidence: [
                'observed' => 1200,
                'baseline' => 300,
                'threshold' => 900,
                'unit' => 'ms',
                'window' => '15m',
            ],
            regionVotes: [
                'us-east' => true,
            ],
            knownCheckIds: [
                'chk_known',
            ],
            knownMetricKeys: [
                'latency',
            ],
            knownRegions: [
                'us-east',
            ],
        );
    }

    /**
     * The whole change on ONE real request, through the real package.
     *
     * Everything else here and in {@see Tests\Unit\Services\Ai\OpenRouterRoutingTest}
     * asserts a half: an array a gateway returns, or a header a hand-made request
     * carries. This drives a production gateway end to end with OpenRouter
     * configured, and reads what actually left the process: `provider.sort` in
     * the JSON body, the metadata ask in the headers, and an answer parsed from a
     * body the recorder had already read.
     *
     * It is also the only test that would catch `laravel/ai` changing where
     * provider options land, since the package merges them into the request body
     * itself ({@see Laravel\Ai\Gateway\OpenRouter\Concerns\BuildsTextRequests}).
     */
    public function test_one_real_gateway_call_carries_the_latency_sort_and_the_metadata_ask(): void
    {
        config([
            'ai.default' => 'openrouter',
            'ai.providers.openrouter.key' => 'sk-test-not-a-real-key',
            'ai.triage.model' => 'deepseek/deepseek-v4-flash',
        ]);

        Http::fake(['openrouter.ai/*' => Http::response($this->completionBody())]);

        $result = (new LaravelAiTriageGateway)->triage($this->triagePayload());

        Http::assertSent(fn ($request): bool => ($request->data()['provider'] ?? null) === ['sort' => 'latency']
            && $request->header(OpenRouterUpstreamRecorder::METADATA_HEADER) === ['enabled']);

        // The answer survived the recorder reading the body it was parsed from.
        $this->assertSame('warn', $result->severity);
        $this->assertSame('Check the upstream dependency: latency tripled against its baseline.', $result->recommendation);
    }

    /**
     * The line survives production's own log level, which is `warning`.
     *
     * MEASURED on the box: `LOG_CHANNEL=stack`, `LOG_STACK=single`,
     * `LOG_LEVEL=warning`. Every other test here asserts through `Log::spy()`,
     * which replaces the log manager and therefore cannot see a level at all, so
     * the whole instrument could be, and was, silent in production while six
     * green tests said otherwise. This is the only test that measures the level.
     *
     * It redirects the two PATHS and nothing else: the LEVELS are the ones
     * `config/logging.php` resolves, because they are what is under test. Written
     * against the real log manager rather than a spy for the same reason.
     */
    public function test_the_upstream_line_survives_a_warning_level_application_log(): void
    {
        $logs = $this->temporaryLogDirectory();

        config([
            'logging.default' => 'stack',
            'logging.channels.single.level' => 'warning',
            'logging.channels.single.path' => $logs.'/application.log',
            'logging.channels.'.OpenRouterUpstreamRecorder::LOG_CHANNEL.'.path' => $logs.'/ai-routing.log',
        ]);

        // The manager caches a resolved channel, so a channel built before the
        // config above would keep the real storage path and the real level.
        Log::forgetChannel('stack');
        Log::forgetChannel('single');
        Log::forgetChannel(OpenRouterUpstreamRecorder::LOG_CHANNEL);

        Http::fake(['openrouter.ai/*' => Http::response(self::METADATA_BODY, 200, ['Content-Type' => 'application/json'])]);

        Http::post('https://openrouter.ai/api/v1/chat/completions', []);

        // Globbed rather than named: the channel's driver dates its filename, and
        // what matters is the content, not which driver produced it.
        $written = implode('', array_map('file_get_contents', glob($logs.'/ai-routing*.log') ?: []));

        $this->assertStringContainsString('OpenRouter answered an AI request.', $written);
        $this->assertStringContainsString('CoreWeave', $written);

        // And it did not reach the application log, where `warning` would have
        // dropped it. An empty file here with a full file above is the whole
        // claim: the instrument has a level of its own.
        $this->assertSame('', implode('', array_map('file_get_contents', glob($logs.'/application*.log') ?: [])));
    }

    /**
     * The channel's level cannot be reached by `LOG_LEVEL`.
     *
     * The bug being fixed was a level inherited from a global knob, so hanging
     * the fix on a channel that reads the SAME knob would look fixed and behave
     * identically. This poisons `LOG_LEVEL` to production's value and re-reads
     * the config file: the assertion on `single` is what proves the poison took,
     * so the assertion on the routing channel cannot pass vacuously.
     *
     * Both env arrays are written because `Env` reads `$_ENV` and `$_SERVER`
     * before it reaches `getenv()`, so `putenv()` alone would be a no-op against
     * a value that is already loaded, and this test would then certify itself.
     */
    public function test_the_routing_channel_level_is_not_read_from_the_global_log_level(): void
    {
        $original = [
            'env' => $_ENV['LOG_LEVEL'] ?? null,
            'server' => $_SERVER['LOG_LEVEL'] ?? null,
        ];

        $_ENV['LOG_LEVEL'] = 'warning';
        $_SERVER['LOG_LEVEL'] = 'warning';

        try {
            $channels = (require config_path('logging.php'))['channels'];
        } finally {
            $this->restoreEnvValue('LOG_LEVEL', $original);
        }

        // The poison took: every channel that reads the global knob moved.
        $this->assertSame('warning', $channels['single']['level']);

        // This one did not. Monolog records anything at or below `info`.
        $this->assertContains($channels[OpenRouterUpstreamRecorder::LOG_CHANNEL]['level'], ['debug', 'info']);
    }

    /**
     * An unset variable leaves the instrument WORKING.
     *
     * A knob whose default is silence is the same bug wearing a different name,
     * and nothing in a deployment guarantees the variable is set: it is absent
     * from production's `.env` today.
     */
    public function test_the_routing_channel_records_when_its_own_level_variable_is_unset(): void
    {
        $original = [
            'env' => $_ENV['AI_ROUTING_LOG_LEVEL'] ?? null,
            'server' => $_SERVER['AI_ROUTING_LOG_LEVEL'] ?? null,
        ];

        unset($_ENV['AI_ROUTING_LOG_LEVEL'], $_SERVER['AI_ROUTING_LOG_LEVEL']);

        try {
            $channels = (require config_path('logging.php'))['channels'];
        } finally {
            $this->restoreEnvValue('AI_ROUTING_LOG_LEVEL', $original);
        }

        $this->assertContains($channels[OpenRouterUpstreamRecorder::LOG_CHANNEL]['level'], ['debug', 'info']);
    }

    public function test_the_metadata_header_is_sent_on_every_openrouter_request(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(self::METADATA_BODY, 200, ['Content-Type' => 'application/json'])]);

        Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'deepseek/deepseek-v4-flash']);

        Http::assertSent(fn ($request): bool => $request->header(OpenRouterUpstreamRecorder::METADATA_HEADER) === ['enabled']);
    }

    /**
     * The header is scoped to OpenRouter, and the scope is not cosmetic: this
     * middleware is global, so every relay probe, proxy fetch and mailer call in
     * the application passes through it. A vendor header on a customer's own
     * target would be a request fingerprint we never agreed to send.
     */
    public function test_no_other_host_receives_the_header_or_is_read_for_metadata(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(self::METADATA_BODY, 200, ['Content-Type' => 'application/json'])]);

        Http::post('https://relay.uptizm.com/probe', ['url' => 'https://example.com']);

        Http::assertSent(fn ($request): bool => $request->header(OpenRouterUpstreamRecorder::METADATA_HEADER) === []);

        // Asserted on `channel` rather than `info`, and that is not cosmetic: the
        // recorder writes through `Log::channel()`, so a spy never sees `info` on
        // the manager itself and `shouldNotHaveReceived('info')` would pass here
        // even if this host WERE being recorded.
        Log::shouldNotHaveReceived('channel');
    }

    public function test_the_upstream_that_served_the_call_is_logged_with_its_duration(): void
    {
        $this->spyOnRoutingChannel();
        Http::fake(['openrouter.ai/*' => Http::response(self::METADATA_BODY, 200, ['Content-Type' => 'application/json'])]);

        Http::post('https://openrouter.ai/api/v1/chat/completions', ['model' => 'deepseek/deepseek-v4-flash']);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'OpenRouter')
                && $context['provider'] === 'CoreWeave'
                && $context['model'] === 'deepseek/deepseek-v4-flash'
                && $context['status'] === 200
                && $context['attempt'] === 1
                && $context['strategy'] === 'direct'
                && $context['region'] === 'iad'
                && is_int($context['duration_ms']))
            ->once();
    }

    /**
     * The body is rewound after the recorder has read it.
     *
     * Asserted on the STREAM POSITION rather than through `Response::json()`,
     * because Guzzle's own `__toString()` seeks to zero before reading: going
     * through the Laravel wrapper would pass whether or not this class rewinds
     * anything, which is a test certifying its own consumer. A stream left at
     * EOF is a landmine for the next reader of the same response, and the
     * failure it produces downstream reads as an empty model answer rather than
     * as an instrument fault.
     */
    public function test_the_response_body_is_rewound_after_being_read_for_metadata(): void
    {
        $body = Utils::streamFor(self::METADATA_BODY);
        $handler = fn (RequestInterface $request, array $options): FulfilledPromise => new FulfilledPromise(
            new Psr7Response(200, ['Content-Type' => 'application/json'], $body),
        );

        (new OpenRouterUpstreamRecorder)($handler)(
            new Psr7Request('POST', 'https://openrouter.ai/api/v1/chat/completions'),
            [],
        )->wait();

        $this->assertSame(0, $body->tell());
        $this->assertSame(self::METADATA_BODY, $body->getContents());
    }

    /**
     * A missing metadata object is REPORTED, not silently skipped.
     *
     * `X-OpenRouter-Metadata` defaults to disabled upstream, so an absent object
     * means the instrument itself stopped working. A null provider on a 200 says
     * that out loud; a suppressed line would leave the routing change
     * unfalsifiable again, which is the state this whole change exists to leave.
     */
    public function test_a_response_without_routing_metadata_logs_a_null_provider(): void
    {
        $this->spyOnRoutingChannel();
        Http::fake(['openrouter.ai/*' => Http::response(
            '{"model":"deepseek/deepseek-v4-flash","choices":[{"message":{"content":"ok"}}]}',
            200,
            ['Content-Type' => 'application/json'],
        )]);

        Http::post('https://openrouter.ai/api/v1/chat/completions', []);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $context['provider'] === null
                && $context['status'] === 200)
            ->once();
    }

    /**
     * A body that cannot be rewound is never read.
     *
     * Streamed completions arrive as a non-seekable socket stream: reading it
     * here would block until the last token and consume the tokens the caller
     * came for. Constructed by hand because `Http::fake()` can only produce
     * in-memory, seekable bodies, so the fake path cannot reach this branch.
     */
    public function test_a_non_seekable_response_body_is_left_alone(): void
    {
        Log::spy();

        $captured = null;
        $handler = function (RequestInterface $request, array $options) use (&$captured): FulfilledPromise {
            $captured = $request;

            return new FulfilledPromise(new Psr7Response(
                200,
                ['Content-Type' => 'text/event-stream'],
                new NoSeekStream(Utils::streamFor(self::METADATA_BODY)),
            ));
        };

        (new OpenRouterUpstreamRecorder)($handler)(
            new Psr7Request('POST', 'https://openrouter.ai/api/v1/chat/completions'),
            [],
        )->wait();

        // The request half still happened: only the reading back is skipped.
        $this->assertSame(['enabled'], $captured->getHeader(OpenRouterUpstreamRecorder::METADATA_HEADER));

        // See the host test: `channel` is the observable call, `info` is not.
        Log::shouldNotHaveReceived('channel');
    }
}
