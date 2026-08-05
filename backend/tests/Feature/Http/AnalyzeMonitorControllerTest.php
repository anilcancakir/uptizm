<?php

namespace Tests\Feature\Http;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Ai\AnalysisGateway;
use App\Services\Ai\AnalysisPayload;
use App\Services\Ai\AnalysisResult;
use App\Services\Ai\FakeAnalysisGateway;
use App\Services\Monitoring\RelayClient;
use App\Support\Monitoring\CheckResult;
use DateTimeImmutable;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
     */
    protected function fakeRelay(MonitorStatus $status): void
    {
        $this->app->bind(RelayClient::class, function () use ($status): RelayClient {
            return new class($status) extends RelayClient
            {
                public function __construct(private readonly MonitorStatus $status) {}

                public function dispatch(Monitor $monitor, string $region): CheckResult
                {
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
                        responseHeaders: [],
                        responseBodyPreview: null,
                        probeRunId: (string) Str::uuid(),
                    );
                }
            };
        });
    }
}
