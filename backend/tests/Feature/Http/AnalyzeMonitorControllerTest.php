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
use Illuminate\Support\Str;
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

    public function test_analyze_requires_the_analysis_ai_tier(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        // A Free team (ai=inbox) cannot use AI monitor analysis.
        $this->actingAsTeamMember('free');

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Pro plan', (string) $response->json('message'));
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
