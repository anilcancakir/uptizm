<?php

namespace Tests\Feature\Http;

use App\Enums\BodyShape;
use App\Enums\LocationBasis;
use App\Enums\MonitorRegion;
use App\Enums\MonitorStatus;
use App\Enums\RegionBasis;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Ai\AnalysisGateway;
use App\Services\Ai\AnalysisPayload;
use App\Services\Ai\AnalysisResult;
use App\Services\Ai\FakeAnalysisGateway;
use App\Services\Ai\LaravelAiAnalysisGateway;
use App\Services\Ai\MetricDiscoveryPayload;
use App\Services\Ai\MetricDiscoveryService;
use App\Services\Monitoring\MetricCandidateExtractor;
use App\Services\Monitoring\RelayClient;
use App\Services\Monitoring\TargetLocation;
use App\Services\Monitoring\TargetLocationResult;
use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\CredentialRedactor;
use DateTimeImmutable;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers POST /api/v1/monitors/analyze: the "Analyze with AI" backend that
 * probes a candidate URL and suggests a starting monitor configuration.
 *
 * The Cloudflare relay worker is unreachable in CI, so every test binds a
 * fake {@see RelayClient} (no network) and, where an analysis runs, the
 * deterministic {@see FakeAnalysisGateway} (no Anthropic call). The SSRF
 * denylist is exercised without either, because request validation rejects a
 * blocked host before any probe is dispatched.
 */
class AnalyzeMonitorControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_returns_a_prefilled_config_from_the_fake_gateway(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.recommended_interval_seconds', 60);
        $response->assertJsonPath('data.recommended_warn_threshold_ms', 800);
        $response->assertJsonPath('data.recommended_critical_threshold_ms', 2000);
        $response->assertJsonPath('data.recommended_regions', ['us-east']);
        $response->assertJsonPath('data.name', 'example.com');
        $response->assertJsonPath('data.url', 'https://example.com/health');
    }

    public function test_analyze_is_open_on_free_for_the_metered_allowance(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $team = $this->actingAsTeamMember('free');
        $allowance = (int) config('plans.tiers.0.limits.ai_analysis_trials');

        $this->assertGreaterThan(0, $allowance, 'Free must grant AI setups.');

        // Every granted setup succeeds and counts down.
        for ($spent = 1; $spent <= $allowance; $spent++) {
            $this->postJson('/api/v1/monitors/analyze', [
                'url' => 'https://example.com/health',
            ])
                ->assertStatus(200)
                ->assertJsonPath(
                    'meta.ai_analysis_trials_remaining',
                    $allowance - $spent,
                );
        }

        $this->assertSame($allowance, (int) $team->fresh()->ai_analysis_trials_used);

        // The next one hits the wall, and says why in those terms.
        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('upgrade.required_plan', 'pro');
        $this->assertStringContainsString(
            "used all {$allowance} free AI monitor setups",
            (string) $response->json('message'),
        );
    }

    public function test_analyze_does_not_spend_an_allowance_on_a_rejected_url(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $team = $this->actingAsTeamMember('free');

        // A validation failure never reaches the probe, so it must not cost the
        // user one of their setups.
        $this->postJson('/api/v1/monitors/analyze', ['url' => 'not-a-url'])
            ->assertStatus(422);

        $this->assertSame(0, (int) $team->fresh()->ai_analysis_trials_used);
    }

    public function test_analyze_does_not_meter_a_tier_that_entitles_it(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $team = $this->actingAsTeamMember('pro');

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])
            ->assertStatus(200)
            // Null, not a number: there is no allowance to count down.
            ->assertJsonPath('meta.ai_analysis_trials_remaining', null);

        $this->assertSame(0, (int) $team->fresh()->ai_analysis_trials_used);
    }

    public function test_analyze_walls_a_free_team_that_spent_its_allowance(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $team = $this->actingAsTeamMember('free');
        $team->forceFill([
            'ai_analysis_trials_used' => (int) config('plans.tiers.0.limits.ai_analysis_trials'),
        ])->save();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Pro plan', (string) $response->json('message'));
        // The tier also travels machine-readably, so the client can offer an
        // upgrade action for exactly this plan instead of parsing the sentence.
        $response->assertJsonPath('upgrade.required_plan', 'pro');
        $response->assertJsonPath('upgrade.feature', 'AI monitor analysis');
    }

    public function test_analyze_rejects_a_cloud_metadata_ssrf_host(): void
    {
        // The fake relay would answer "up" if a probe ever ran; asserting the
        // 422 proves the SSRF guard rejects before any dispatch happens.
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'http://169.254.169.254/',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public function test_analyze_over_budget_degrades_without_calling_the_llm(): void
    {
        // A zero daily cap forces every run over budget. Binding a gateway that
        // throws proves the LLM is never reached: a 200 with a config means the
        // budget guard short-circuited to the deterministic suggestion.
        config(['ai.budget.daily_per_team' => 0]);
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->instance(AnalysisGateway::class, new class implements AnalysisGateway
        {
            public function analyze(AnalysisPayload $payload): AnalysisResult
            {
                throw new RuntimeException('The LLM must not be called when over budget.');
            }
        });
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.recommended_regions', ['us-east']);
        $this->assertIsInt($response->json('data.recommended_warn_threshold_ms'));
        $this->assertStringContainsString('budget', strtolower((string) $response->json('data.rationale')));
    }

    public function test_analyze_degrades_instead_of_500ing_when_the_model_output_is_untrusted(): void
    {
        // The gateway throws a RuntimeException when the model's output is
        // non-conforming twice in a row. That is a bad day for the model, not a
        // broken create flow: the operator still gets a prefilled config.
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->instance(
            AnalysisGateway::class,
            FakeAnalysisGateway::throwing(new RuntimeException('Non-conforming output past the retry.')),
        );
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.recommended_regions', ['us-east']);
        $this->assertIsInt($response->json('data.recommended_warn_threshold_ms'));
        // And it does not blame the budget: the budget is intact, the model is not.
        $rationale = strtolower((string) $response->json('data.rationale'));
        $this->assertStringNotContainsString('budget', $rationale);
        $this->assertStringContainsString('unavailable', $rationale);
    }

    public function test_analyze_degrades_instead_of_500ing_when_the_ai_service_is_unreachable(): void
    {
        // A provider outage, a timeout, or a missing key surfaces as a client
        // ConnectionException. Same rule: degrade, never 500.
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->instance(
            AnalysisGateway::class,
            FakeAnalysisGateway::throwing(new ConnectionException('Connection timed out.')),
        );
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.recommended_regions', ['us-east']);
        $this->assertStringContainsString(
            'unavailable',
            strtolower((string) $response->json('data.rationale')),
        );
    }

    public function test_analyze_degrades_when_the_provider_rate_limits_the_application(): void
    {
        // A provider 429 / 402 / 503 does NOT reach us as a client
        // RequestException: `Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors`
        // (used by the OpenRouter gateway this deploy runs on) maps each one to an
        // `AiException` subclass, and an OpenRouter error delivered in-band with
        // HTTP 200 raises `AiException` too. Neither descends from
        // RuntimeException, so both would 500 the create flow on the most
        // ordinary provider bad day there is.
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->instance(
            AnalysisGateway::class,
            FakeAnalysisGateway::throwing(RateLimitedException::forProvider('openrouter', 429)),
        );
        $team = $this->actingAsTeamMember('free');

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'unavailable',
            strtolower((string) $response->json('data.rationale')),
        );
        // And no model ran, so no trial is charged.
        $this->assertSame(0, (int) $team->fresh()->ai_analysis_trials_used);
    }

    public function test_analyze_does_not_spend_a_trial_when_the_model_fails(): void
    {
        // A trial buys AI analysis. No model ran here, so the meter must not move
        // and the remaining count the client reads must not move with it.
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->instance(
            AnalysisGateway::class,
            FakeAnalysisGateway::throwing(new RuntimeException('Non-conforming output past the retry.')),
        );
        $team = $this->actingAsTeamMember('free');
        $allowance = (int) config('plans.tiers.0.limits.ai_analysis_trials');

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])
            ->assertStatus(200)
            ->assertJsonPath('meta.ai_analysis_trials_remaining', $allowance);

        $this->assertSame(0, (int) $team->fresh()->ai_analysis_trials_used);
    }

    public function test_analyze_does_not_spend_a_trial_when_the_team_is_over_budget(): void
    {
        // Same rule on the other degrade path: over budget the LLM is never
        // called, so there is no AI analysis to charge for.
        config(['ai.budget.daily_per_team' => 0]);
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $team = $this->actingAsTeamMember('free');
        $allowance = (int) config('plans.tiers.0.limits.ai_analysis_trials');

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])
            ->assertStatus(200)
            ->assertJsonPath('meta.ai_analysis_trials_remaining', $allowance);

        $this->assertSame(0, (int) $team->fresh()->ai_analysis_trials_used);
    }

    public function test_analyze_spends_exactly_one_trial_on_a_delivered_analysis(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $team = $this->actingAsTeamMember('free');

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(200);

        $this->assertSame(1, (int) $team->fresh()->ai_analysis_trials_used);
    }

    public function test_analyze_reads_a_json_body_as_an_api_and_prefills_its_slo_target(): void
    {
        // Over budget on purpose, so the classification under test is the
        // DETERMINISTIC path's own reading of the evidence rather than a stub's
        // fixed answer: a fake gateway would return these keys whatever the
        // controller assembled.
        config(['ai.budget.daily_per_team' => 0]);
        $this->fakeRelay(MonitorStatus::Up, ['Content-Type' => 'application/json'], $this->healthBody());
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.service_class', 'json_api');
        $response->assertJsonPath('data.recommended_slo_target', '99.9');
        // Nothing located the target: no CDN header, and the geo lookup is
        // dormant without a token, so the honest reason is "nothing did".
        $response->assertJsonPath('data.region_basis', 'default');
    }

    public function test_analyze_reads_an_html_body_as_a_web_page(): void
    {
        config(['ai.budget.daily_per_team' => 0]);
        $this->fakeRelay(MonitorStatus::Up, ['Content-Type' => 'text/html'], $this->wordpressBody());
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.service_class', 'web_page');
        $response->assertJsonPath('data.recommended_slo_target', '99.9');
    }

    public function test_analyze_reports_a_cdn_edge_basis_behind_cloudflare(): void
    {
        config(['ai.budget.daily_per_team' => 0]);
        $this->fakeRelay(MonitorStatus::Up, $this->cloudflareHeaders(), $this->healthBody());
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        // `default`, not `cdn_edge`, and the pairing below is why. `region_basis`
        // answers "why was THIS region suggested", and on the deterministic path
        // the answer is always the same: because the request asked to probe from
        // it. The lookup's own outcome (`cdn_edge` here) played no part in
        // choosing it, so reporting it as the basis would justify a suggestion
        // with evidence that did not produce it. Only the MODEL, which reads the
        // location facts and can weigh them, may claim any other basis.
        //
        // An earlier revision asserted `cdn_edge` here and passed, which is what
        // a test pinning a fabrication looks like from the inside.
        $response->assertJsonPath('data.region_basis', 'default');
        $response->assertJsonPath('data.recommended_regions', ['us-east']);
        $response->assertJsonPath('data.service_class', 'json_api');
    }

    public function test_a_resolved_geo_lookup_still_does_not_justify_the_region(): void
    {
        // The strongest case for the rule: the lookup SUCCEEDED. A geo provider
        // named a country and a region, and the deterministic path still reports
        // `default`, because it did not use any of that to pick the region it
        // suggests. `recommended_regions` below is the whole argument: it is the
        // request's probe region, unchanged, on every branch of this path.
        config(['ai.budget.daily_per_team' => 0]);
        $this->fakeRelay(MonitorStatus::Up, ['Content-Type' => 'application/json'], $this->healthBody());
        $this->cannedTargetLocation(new TargetLocationResult(
            ips: ['93.184.216.34'],
            cdn: null,
            country: 'DE',
            region: 'Hesse',
            locationBasis: LocationBasis::Geoip,
        ));
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.region_basis', 'default');
        $response->assertJsonPath('data.recommended_regions', ['us-east']);
    }

    public function test_both_models_one_analyze_calls_inherit_the_surface_the_deployment_configured(): void
    {
        // Re-evaluating the config FILE rather than reading the resolved value,
        // because the fallback chain only shows itself while `env()` is being
        // read. A deployment that has moved the AI surface to another provider
        // sets `AI_DEFAULT` and `AI_TRIAGE_MODEL` and nothing else, and a literal
        // default here would ask THAT provider for an Anthropic-native id it does
        // not serve. The gateway's own degrade would then answer deterministically
        // on every request with only a log line, so the feature would ship dark
        // and look healthy: the worst failure shape this endpoint has.
        //
        // BOTH keys, because one analyze spends both: the suggestion turn reads
        // `analysis.model` and `MetricDiscoveryService` reads
        // `metric_discovery.model` on the same request. Asserting only the first
        // would let the second regress to a literal id and answer every request
        // with an empty `suggested_metrics`, green suite and all.
        //
        // The override has to move all THREE channels, and the earlier version of
        // this test measured nothing because it moved one. `Env::getRepository()`
        // reads `$_SERVER` and `$_ENV` BEFORE `getenv()`, so a bare `putenv()` is
        // inert for any key the loaded `.env` already holds, which is every key
        // here. It passed on a machine whose `.env` happened to carry the expected
        // value and failed in CI, where `.env.example` carries another.
        //
        // The sentinel is deliberately a model id no `.env` in this repo contains,
        // so the assertion cannot come out true by inheriting the environment.
        $sentinel = 'test-provider/sentinel-model';
        $keys = ['AI_TRIAGE_MODEL' => $sentinel, 'AI_ANALYSIS_MODEL' => null, 'AI_METRIC_DISCOVERY_MODEL' => null];
        $previous = [];

        foreach ($keys as $key => $value) {
            // `=== false` rather than a falsy test: an empty string is a value
            // the restore has to put back, and `getenv()` answers a miss with
            // `false`. Collapsing the two would leak an unset key into whatever
            // test the parallel runner schedules next in this process.
            $current = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            $previous[$key] = $current === false ? null : $current;

            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);

                continue;
            }

            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv($key.'='.$value);
        }

        try {
            $config = require config_path('ai.php');

            $this->assertSame($sentinel, $config['analysis']['model']);
            $this->assertSame($sentinel, $config['metric_discovery']['model']);
            $this->assertSame($config['triage']['model'], $config['analysis']['model']);
            $this->assertSame($config['triage']['model'], $config['metric_discovery']['model']);
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key], $_ENV[$key]);
                    putenv($key);

                    continue;
                }

                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv($key.'='.$value);
            }
        }
    }

    public function test_analyze_refuses_a_url_carrying_a_credential_in_its_userinfo(): void
    {
        // Laravel's `url` rule accepts `https://user:pass@host/path` (measured),
        // and this endpoint hands the URL to the prompt as a TRUSTED fact on the
        // turn that also holds the web-search tool. The premise that makes a
        // free-text search query safe is that nothing secret is in the model's
        // context, so this is the one inlet that premise cannot survive, and it
        // exists today, before any credential field is added.
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://ops:s3cr3t@example.com/health',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
        // Refused rather than stripped: an operator who pasted a credential is
        // told, instead of quietly having it removed and the target probed
        // without it.
        $this->assertStringNotContainsString('s3cr3t', $response->getContent());
    }

    public function test_a_query_string_token_never_reaches_the_prompt(): void
    {
        // The remaining credential shape once userinfo is refused, and a common
        // one: a health endpoint gated by `?token=`. The full URL still goes to
        // the probe, because that is what we must fetch; what a third party is
        // SHOWN is scheme, host and path.
        $this->fakeRelay(MonitorStatus::Up, $this->cloudflareHeaders(), $this->healthBody());
        $this->stubMetricDiscovery();
        $gateway = $this->recordingGateway();
        $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health?token=T0KENSECRET&verbose=1',
        ])->assertStatus(200);

        $message = $gateway->payload->buildUserMessage();

        $this->assertStringNotContainsString('T0KENSECRET', $message);
        $this->assertStringContainsString('url: https://example.com/health', $message);
        // The probe still received the whole thing, query included.
        $this->assertStringContainsString('token=T0KENSECRET', $gateway->payload->url);
    }

    public function test_analyze_hands_the_gateway_the_allowlisted_headers_the_digest_and_the_posture(): void
    {
        $this->fakeRelay(MonitorStatus::Up, $this->cloudflareHeaders(), $this->healthBody());
        $this->stubMetricDiscovery();
        $gateway = $this->recordingGateway();
        $team = $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(200);

        $payload = $gateway->payload;
        $this->assertInstanceOf(AnalysisPayload::class, $payload);

        // The allowlist decides both WHICH headers a prompt may read and in what
        // order, so a hostile target cannot influence either.
        $this->assertSame(
            ['content-type', 'server', 'cf-cache-status', 'cf-ray'],
            array_keys($payload->responseHeaders),
        );

        // The body reached the model as a shape, with a leaf path a metric
        // proposal can actually be expressed against.
        $this->assertSame(BodyShape::Json, $payload->digest?->shape);
        $this->assertStringContainsString(
            'checks.database.details.latency_ms',
            (string) $payload->digest?->digest,
        );

        // The team id is what meters a research turn, and the posture is what
        // keeps a location from being invented.
        $this->assertSame((string) $team->id, $payload->teamId);
        $this->assertSame('Cloudflare', $payload->targetLocation?->cdn);
        $this->assertSame(LocationBasis::CdnEdge, $payload->targetLocation?->locationBasis);
        $this->assertNull($payload->targetLocation?->country);
        $this->assertStringContainsString('target_country: origin_unknown', $payload->buildUserMessage());
    }

    public function test_analyze_never_lets_a_credential_header_reach_the_prompt_or_the_geo_lookup(): void
    {
        // The probe is read-only today, but once the analyze request carries the
        // operator's own credential `Set-Cookie` is an authenticated session
        // token and `Authorization` echoes the credential itself. Neither may
        // cross the prompt boundary, and neither may reach the geo provider.
        $this->fakeRelay(MonitorStatus::Up, [
            ...$this->cloudflareHeaders(),
            'Set-Cookie' => 'session=SECRETVALUE; Path=/; HttpOnly',
            'Authorization' => 'Bearer SECRETVALUE',
            'WWW-Authenticate' => 'Basic realm="SECRETVALUE"',
        ], $this->healthBody());
        $this->stubMetricDiscovery();
        $location = $this->cannedTargetLocation();
        $gateway = $this->recordingGateway();
        $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(200);

        $message = $gateway->payload?->buildUserMessage() ?? '';
        $this->assertNotSame('', $message, 'The gateway must have been handed a payload.');
        $this->assertStringNotContainsString('SECRETVALUE', $message);

        // The NAMES are withheld too: "this target sent a Set-Cookie" is itself
        // evidence the setup prompt has no consumer for.
        foreach (['set-cookie', 'authorization', 'www-authenticate'] as $name) {
            $this->assertStringNotContainsString($name, strtolower($message));
        }

        // Same set, one layer down: the location service is handed the filtered
        // headers, never the raw ones.
        $this->assertSame(
            ['content-type', 'server', 'cf-cache-status', 'cf-ray'],
            array_keys($location->headers ?? []),
        );
    }

    public function test_analyze_probes_the_target_with_the_submitted_credential(): void
    {
        $relay = $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
            'auth_config' => [
                'type' => 'basic',
                'username' => 'ops',
                'password' => 'SECRETPASSWORD',
            ],
        ])->assertStatus(200);

        // Read the ACCESSOR and never `getAttributes()`: the `encrypted:array`
        // cast holds ciphertext even on this unsaved instance, and
        // `RelayClient::buildSpec()` reads the decrypted array. Asserting on the
        // raw attribute would chase a ghost.
        $probed = $relay->probed;
        $this->assertInstanceOf(Monitor::class, $probed);
        $this->assertSame([
            'type' => 'basic',
            'username' => 'ops',
            'password' => 'SECRETPASSWORD',
        ], $probed->auth_config);

        // Transient means transient: an analyze leaves no row behind, which is
        // also why its log line is the only record that it happened.
        $this->assertFalse($probed->exists);
        $this->assertSame(0, Monitor::query()->count());
    }

    public function test_analyze_validates_the_credential_shape(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        // The shared `ValidatesAuthConfig` rules apply here exactly as they do
        // on create: a basic credential without its password is refused before
        // any probe runs, rather than probing unauthenticated and reporting a
        // 401 as the target's own answer.
        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
            'auth_config' => ['type' => 'basic', 'username' => 'ops'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('auth_config.password');
    }

    public function test_a_credential_the_target_echoes_reaches_neither_prompt_nor_the_response(): void
    {
        // The discriminating case for the whole control. The worker sends
        // `Basic base64("user:pass")`, so a debug page echoing its request
        // headers prints THAT and never the pair, which is why a redactor built
        // from the submitted username and password would pass every other test
        // here and fail this one.
        $secret = 'SECRETPASSWORD';
        $wireForm = base64_encode('ops:'.$secret);

        $this->fakeRelay(MonitorStatus::Up, $this->cloudflareHeaders(), $this->echoingBody($wireForm));
        $discovery = $this->recordingMetricDiscovery();
        $gateway = $this->recordingGateway();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
            'auth_config' => [
                'type' => 'basic',
                'username' => 'ops',
                'password' => $secret,
            ],
        ]);

        $response->assertStatus(200);

        $analysisMessage = $gateway->payload?->buildUserMessage() ?? '';
        $discoveryMessage = $discovery->captured?->buildUserMessage() ?? '';

        // BOTH prompts, because one analyze builds two through two payload
        // classes. The marker assertions come first and are not decoration:
        // a credential is absent from a prompt that was never built too, so
        // without them both halves of this test could pass vacuously.
        $this->assertStringContainsString(CredentialRedactor::MARKER, $analysisMessage);
        $this->assertStringContainsString(CredentialRedactor::MARKER, $discoveryMessage);

        foreach ([$analysisMessage, $discoveryMessage, (string) $response->getContent()] as $rendered) {
            $this->assertStringNotContainsString($wireForm, $rendered);
            $this->assertStringNotContainsString($secret, $rendered);
        }
    }

    public function test_a_credentialled_analyze_is_logged_with_the_host_and_the_type_and_never_a_value(): void
    {
        Log::spy();
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $team = $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health?token=QUERYSECRET',
            'auth_config' => ['type' => 'bearer', 'token' => 'SECRETTOKEN'],
        ])->assertStatus(200);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($team): bool {
                $rendered = $message.' '.json_encode($context);

                return str_contains($message, 'operator-supplied credential')
                    && $context['team_id'] === (string) $team->id
                    // The HOST, not the URL: a monitor target is frequently
                    // `…/health?token=…` and a log line is a place a query
                    // string would sit forever.
                    && $context['host'] === 'example.com'
                    && $context['auth_type'] === 'bearer'
                    && ! str_contains($rendered, 'SECRETTOKEN')
                    && ! str_contains($rendered, 'QUERYSECRET');
            })
            ->once();
    }

    public function test_an_auth_config_of_type_none_behaves_as_an_unauthenticated_analyze(): void
    {
        Log::spy();
        $relay = $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
            'auth_config' => ['type' => 'none'],
        ])->assertStatus(200);

        // The worker sends no header for `none`, so nothing was exposed and the
        // audit line stays silent: the same boundary `CredentialRedactor::for()`
        // draws, and the reason the ordinary path gains no noise.
        $this->assertSame(['type' => 'none'], $relay->probed?->auth_config);
        Log::shouldNotHaveReceived('info');
    }

    public function test_a_degraded_response_carries_the_same_shape_as_a_modelled_one(): void
    {
        // The client decodes one shape. A bad provider day changes the VALUES it
        // reads, never the keys it reads them from.
        $this->fakeRelay(MonitorStatus::Up, $this->cloudflareHeaders(), $this->healthBody());
        $this->stubMetricDiscovery();
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $modelled = array_keys((array) $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(200)->json('data'));

        config(['ai.budget.daily_per_team' => 0]);

        $degraded = array_keys((array) $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(200)->json('data'));

        sort($modelled);
        sort($degraded);

        $this->assertSame($modelled, $degraded);
        $this->assertContains('service_class', $degraded);
        $this->assertContains('region_basis', $degraded);
        $this->assertContains('recommended_slo_target', $degraded);
    }

    public function test_a_degraded_analyze_reports_low_confidence(): void
    {
        // Over budget on purpose: no model ran, so the deterministic path
        // answered. That is the only condition `low` gates on.
        config(['ai.budget.daily_per_team' => 0]);
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.confidence', 'low');
    }

    public function test_a_modelled_analyze_with_an_inferred_region_basis_reports_medium_confidence(): void
    {
        // A model answered (the fake gateway is bound, not thrown), but its
        // `region_basis` is `default`, the inferred member of the set. A
        // digest is present too, which proves `medium` gates on the BASIS and
        // not merely on the absence of one.
        $this->fakeRelay(MonitorStatus::Up, $this->cloudflareHeaders(), $this->healthBody());
        $this->stubMetricDiscovery();
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.region_basis', RegionBasis::Default->value);
        $response->assertJsonPath('data.confidence', 'medium');
    }

    public function test_a_modelled_analyze_with_a_measured_basis_and_a_digest_reports_high_confidence(): void
    {
        // A model answered with a MEASURED basis (`geoip`) over a probe that
        // returned a body, so both conditions `high` requires are met.
        $this->fakeRelay(MonitorStatus::Up, $this->cloudflareHeaders(), $this->healthBody());
        $this->stubMetricDiscovery();
        $this->app->instance(AnalysisGateway::class, new class implements AnalysisGateway
        {
            public function analyze(AnalysisPayload $payload): AnalysisResult
            {
                return new AnalysisResult(
                    recommendedIntervalSeconds: 60,
                    recommendedWarnThresholdMs: 800,
                    recommendedCriticalThresholdMs: 2000,
                    recommendedRegions: [MonitorRegion::USEast->value],
                    rationale: 'Recorded suggestion.',
                    regionBasis: RegionBasis::Geoip->value,
                );
            }
        });
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.region_basis', RegionBasis::Geoip->value);
        $response->assertJsonPath('data.confidence', 'high');
    }

    public function test_a_model_reporting_its_own_confidence_cannot_influence_the_wire_value(): void
    {
        // The fake's answer carries a `confidence` no real schema offers a
        // model, deliberately set to the OPPOSITE of what the evidence
        // (`region_basis: default`, an inferred value) actually supports. If
        // the controller ever forwarded a model's self-report, this would
        // read `high`; it must still read the derived `medium`.
        $this->fakeRelay(MonitorStatus::Up, $this->cloudflareHeaders(), $this->healthBody());
        $this->stubMetricDiscovery();
        $this->app->instance(
            AnalysisGateway::class,
            FakeAnalysisGateway::selfReportingConfidence('high'),
        );
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.region_basis', RegionBasis::Default->value);
        $response->assertJsonPath('data.confidence', 'medium');
    }

    public function test_the_deterministic_slo_table_stays_inside_the_catalogs_the_gateway_owns(): void
    {
        // The deterministic path names a service class and an SLO target without
        // a model, so its table is the one place either catalog can drift
        // unnoticed: a value outside the schema's enum is not a prefill the
        // operator's form can hold.
        $table = MonitorController::SLO_TARGET_BY_SERVICE_CLASS;

        $this->assertSame(
            LaravelAiAnalysisGateway::SERVICE_CLASSES,
            array_keys($table),
            'Every service class needs a target, and none may be invented.',
        );

        foreach ($table as $serviceClass => $target) {
            $this->assertContains($target, LaravelAiAnalysisGateway::SLO_TARGETS, $serviceClass);
        }

        // The two ends of the table carry its reasoning: nothing in one probe
        // justifies the strictest target, and an unread service gets none.
        $this->assertNotContains('99.99', $table);
        $this->assertSame('none', $table['unknown']);
        $this->assertNotSame($table['health_endpoint'], $table['web_page']);
    }

    public function test_analyze_is_throttled_per_actor(): void
    {
        // One accepted request costs a live relay probe plus up to two model
        // calls, and `api/v1` never calls throttleApi(), so the named limiter is
        // the only thing bounding how fast one member can spend it.
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        // Pro entitles AI analysis outright, so a 403 plan wall can never be
        // mistaken here for the 429 this test is looking for.
        $this->actingAsTeamMember('pro');

        $statuses = [];

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $statuses[] = $this->postJson('/api/v1/monitors/analyze', [
                'url' => 'https://example.com/health',
            ])->getStatusCode();

            if (end($statuses) === 429) {
                break;
            }
        }

        $this->assertContains(429, $statuses, 'The analyze route must carry a limiter.');
        // The floor is what keeps the limiter off a legitimate operator: the
        // Free allowance plus the wall check is four requests in one sitting,
        // and a human comparing two candidate URLs adds more.
        $this->assertGreaterThanOrEqual(
            5,
            count(array_filter($statuses, fn (int $status): bool => $status === 200)),
            'A human clicking Analyze must get at least five a minute.',
        );
    }

    public function test_analyze_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(401);
    }

    public function test_analyze_requires_a_current_team(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);

        $user = User::factory()->create(['current_team_id' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(403);
    }

    public function test_analyze_validates_the_url(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'not-a-url',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    /**
     * Authenticate as a fresh user owning a personal team.
     */
    protected function actingAsTeamMember(string $plan = 'pro'): Team
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);
        // AI monitor analysis is an analysis-tier (Pro+) feature; the base
        // MagicStarter Team does not fill `plan`, so set it directly.
        $team->forceFill(['plan' => $plan])->save();

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $team;
    }

    /**
     * Bind a fake {@see RelayClient} so the analyze probe never hits the
     * network: the transient monitor it is handed resolves to a fixed result.
     *
     * [$headers] and [$content] are what the evidence pipeline reads, so they
     * default to what a bare probe carries (none, and no captured body) and a
     * test that cares supplies a realistic set.
     *
     * The double is RETURNED and records the transient monitor it was handed,
     * because that instance is the whole probe spec: the only honest way to ask
     * "did the credential actually reach the target" is to read what the relay
     * was asked to send.
     *
     * @param  array<string, string>  $headers  RAW response headers, in the target's own
     *                                          casing, exactly as the worker returns them.
     */
    protected function fakeRelay(MonitorStatus $status, array $headers = [], ?string $content = null): object
    {
        $double = new class($status, $headers, $content) extends RelayClient
        {
            public ?Monitor $probed = null;

            /**
             * @param  array<string, string>  $headers
             */
            public function __construct(
                private readonly MonitorStatus $status,
                private readonly array $headers,
                private readonly ?string $content,
            ) {}

            public function dispatch(Monitor $monitor, string $region): CheckResult
            {
                $this->probed = $monitor;

                return new CheckResult(
                    monitorId: (string) ($monitor->id ?? ''),
                    region: $region,
                    checkedAt: new DateTimeImmutable,
                    status: $this->status,
                    statusCode: $this->status === MonitorStatus::Up ? 200 : 503,
                    responseMs: 180,
                    errorMessage: null,
                    timingDnsMs: 10,
                    timingConnectMs: 20,
                    timingTlsMs: 30,
                    timingTtfbMs: 100,
                    timingDownloadMs: 20,
                    responseHeaders: $this->headers,
                    // The worker sends a 10 KiB preview beside the full body,
                    // so a fake that carries one without the other would let
                    // the prompt look tidier than it is.
                    responseBodyPreview: $this->content !== null
                        ? mb_substr($this->content, 0, 10240)
                        : null,
                    probeRunId: (string) Str::uuid(),
                    content: $this->content,
                );
            }
        };

        $this->app->instance(RelayClient::class, $double);

        return $double;
    }

    /**
     * Bind an {@see AnalysisGateway} that records the payload it was handed.
     *
     * The prompt is never logged and must never be, so capturing the payload at
     * the gateway boundary is the only way to assert what the model would have
     * been shown.
     */
    protected function recordingGateway(): object
    {
        $gateway = new class implements AnalysisGateway
        {
            public ?AnalysisPayload $payload = null;

            public function analyze(AnalysisPayload $payload): AnalysisResult
            {
                $this->payload = $payload;

                return new AnalysisResult(
                    recommendedIntervalSeconds: 60,
                    recommendedWarnThresholdMs: 800,
                    recommendedCriticalThresholdMs: 2000,
                    recommendedRegions: [MonitorRegion::USEast->value],
                    rationale: 'Recorded suggestion.',
                );
            }
        };

        $this->app->instance(AnalysisGateway::class, $gateway);

        return $gateway;
    }

    /**
     * Bind a {@see TargetLocation} that records the headers it was asked to read
     * and answers with [$canned], defaulting to an unresolved lookup.
     *
     * The real service is unit-tested against its own inputs; what a controller
     * test needs from it is which headers reached it and a deterministic basis to
     * map from.
     */
    protected function cannedTargetLocation(?TargetLocationResult $canned = null): object
    {
        $double = new class($canned) extends TargetLocation
        {
            /** @var array<string, string>|null */
            public ?array $headers = null;

            public function __construct(private readonly ?TargetLocationResult $canned) {}

            public function resolve(string $url, array $headers, array $ips = []): TargetLocationResult
            {
                $this->headers = $headers;

                return $this->canned ?? new TargetLocationResult(
                    ips: $ips,
                    cdn: null,
                    country: null,
                    region: null,
                    locationBasis: LocationBasis::Unresolved,
                );
            }
        };

        $this->app->instance(TargetLocation::class, $double);

        return $double;
    }

    /**
     * Stub metric discovery out of the analyze request.
     *
     * Required by every test that gives the probe a BODY: with one, the real
     * {@see MetricDiscoveryService} finds candidates, spends a budget unit and
     * asks a live provider to select among them. The suite must never make that
     * call, and discovery has its own test file.
     */
    protected function stubMetricDiscovery(): void
    {
        $this->app->instance(MetricDiscoveryService::class, new class extends MetricDiscoveryService
        {
            public function __construct() {}

            public function discover(Monitor $monitor, ?string $body, string $teamId): array
            {
                return [];
            }
        });
    }

    /**
     * Bind a {@see MetricDiscoveryService} that records the payload the SECOND
     * prompt would have been built from, and proposes nothing.
     *
     * The service builds its payload internally and hands it straight to a
     * gateway, so this overrides `discover()` with the one half that matters
     * here: the real {@see MetricCandidateExtractor} over the real body, then
     * the real payload. No budget unit, no provider call, no suggestions.
     *
     * It exists because one analyze builds TWO prompts through two payload
     * classes, and a control asserted against only {@see AnalysisPayload} is a
     * fix landing on one of two identical sites.
     */
    protected function recordingMetricDiscovery(): object
    {
        $service = new class(new MetricCandidateExtractor) extends MetricDiscoveryService
        {
            public ?MetricDiscoveryPayload $captured = null;

            public function __construct(protected MetricCandidateExtractor $extractor) {}

            public function discover(Monitor $monitor, ?string $body, string $teamId): array
            {
                if ($body === null || trim($body) === '') {
                    return [];
                }

                $this->captured = $this->payload($monitor, $this->extractor->extract($body));

                return [];
            }
        };

        $this->app->instance(MetricDiscoveryService::class, $service);

        return $service;
    }

    /**
     * A realistic Cloudflare-fronted response header set, in the target's own
     * casing, including two names the allowlist drops.
     *
     * @return array<string, string>
     */
    protected function cloudflareHeaders(): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'Server' => 'cloudflare',
            'CF-RAY' => '8f2b1c9a4e7d0123-FRA',
            'CF-Cache-Status' => 'DYNAMIC',
            'Strict-Transport-Security' => 'max-age=31536000',
            'X-Secret-Token' => 'nothing-unenumerated-survives',
        ];
    }

    /**
     * A health body that echoes the request's own `Authorization` header, the
     * exact shape the redactor exists for.
     *
     * Written inline rather than added to `tests/fixtures/content/` because the
     * echoed value has to be built from the credential the test submits; a
     * fixture file would have to hardcode one and would then keep passing after
     * the test changed its secret.
     *
     * The echo sits FIRST so it lands inside `AnalysisPayload`'s 500-character
     * body preview as well as in the digest: a redactor that missed would then
     * show up in both renderers rather than in whichever one happened to reach
     * it.
     */
    protected function echoingBody(string $wireForm): string
    {
        return (string) json_encode([
            'request' => [
                'headers' => [
                    'authorization' => 'Basic '.$wireForm,
                ],
            ],
            'status' => 'ok',
            'latency_ms' => 42,
        ], JSON_PRETTY_PRINT);
    }

    /**
     * The IETF-shaped health payload fixture, shared with the digest's own tests.
     */
    protected function healthBody(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/content/health-endpoint.json'));
    }

    /**
     * The WordPress page fixture, shared with the digest's own tests.
     */
    protected function wordpressBody(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/content/wordpress-page.html'));
    }
}
