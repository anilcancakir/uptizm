<?php

namespace Tests\Feature\Http;

use App\Enums\AiMode;
use App\Enums\MetricBand;
use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MonitorRegion;
use App\Enums\MonitorStatus;
use App\Enums\Plan;
use App\Enums\ThresholdDirection;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Jobs\PerformMonitorCheck;
use App\Models\EscalationPolicy;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetric;
use App\Models\User;
use App\Services\Monitoring\MetricCandidateExtractor;
use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the team-scoped monitor CRUD + lifecycle surface: the SSRF host
 * denylist on create, the 404-mask on cross-team access, the auth_config
 * secret redaction on output, and the pause lifecycle transition.
 *
 * Route wiring for /api/v1/monitors is a later step, so this test registers
 * the controller against a Sanctum-guarded route group of its own to
 * exercise the full HTTP path today.
 */
class MonitorControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(function (): void {
            Route::get('monitors', [MonitorController::class, 'index']);
            Route::post('monitors', [MonitorController::class, 'store']);
            Route::get('monitors/{monitor}', [MonitorController::class, 'show']);
            Route::put('monitors/{monitor}', [MonitorController::class, 'update']);
            Route::delete('monitors/{monitor}', [MonitorController::class, 'destroy']);
            Route::post('monitors/{monitor}/pause', [MonitorController::class, 'pause']);
            Route::post('monitors/{monitor}/resume', [MonitorController::class, 'resume']);
            Route::post('monitors/{monitor}/test', [MonitorController::class, 'test']);
        });
    }

    public function test_store_creates_monitor_and_returns_check_interval_sec(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();
        // Paid, because the fan-out this asserts needs more than the one region
        // Free allows; the interval assertion below holds on either tier.
        $team->forceFill(['plan' => Plan::Pro->value])->save();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'regions' => [
                'us-east',
                'eu-west',
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.check_interval_sec', 180);
        $response->assertJsonPath('data.status', 'active');

        // A first check is fanned out per configured region.
        Queue::assertPushed(PerformMonitorCheck::class, 2);
    }

    public function test_store_rejects_cloud_metadata_link_local_url(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'url' => 'http://169.254.169.254/',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
        Queue::assertNothingPushed();
    }

    public function test_store_rejects_integer_ip_literal_for_loopback(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        // 2130706433 is the 32-bit integer form of 127.0.0.1; without literal
        // normalization it slips past the dotted-quad denylist.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'url' => 'http://2130706433/',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
        Queue::assertNothingPushed();
    }

    public function test_store_drops_null_expected_status_code_and_applies_db_default(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();

        // A payload carrying an explicit null must not 500 on the NOT NULL
        // column; the key is dropped so the DB default (200) applies.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'expected_status_code' => null,
        ]);

        $response->assertStatus(201);

        $monitor = Monitor::query()->where('team_id', $team->id)->sole();
        $this->assertSame(200, $monitor->expected_status_code);
    }

    public function test_store_rejects_rfc1918_private_url(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'url' => 'http://10.0.0.5/',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public function test_store_rejects_loopback_and_internal_hosts(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        foreach (['http://localhost/', 'http://127.0.0.1/', 'http://api.internal/'] as $url) {
            $response = $this->postJson('/api/v1/monitors', [
                ...$this->validPayload(),
                'url' => $url,
            ]);

            $response->assertStatus(422, "Expected {$url} to be rejected.");
            $response->assertJsonValidationErrors('url');
        }
    }

    public function test_free_team_cannot_exceed_its_monitor_quota(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();

        // Fill the Free tier's 10-monitor allowance, then attempt one more.
        for ($i = 0; $i < 10; $i++) {
            $this->makeMonitor($team->id);
        }

        $response = $this->postJson('/api/v1/monitors', $this->validPayload());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('plan');
        $this->assertSame(10, Monitor::query()->where('team_id', $team->id)->count());
    }

    public function test_free_team_cannot_check_faster_than_its_plan_floor(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        // 30s is below the Free tier's 180s floor.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'check_interval_sec' => 30,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('check_interval_sec');
    }

    public function test_a_paid_team_may_check_faster_than_the_free_floor(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();
        $team->forceFill(['plan' => Plan::Pro->value])->save();

        // 30s is the Pro tier's floor, so it is allowed for a paid team.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'check_interval_sec' => 30,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.check_interval_sec', 30);
    }

    public function test_free_team_cannot_create_a_monitor_beyond_its_region_allowance(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        // Free allows one region per monitor and a create has nothing stored to
        // grandfather, so the allowance binds on the payload alone.
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'regions' => [
                'us-east',
                'eu-west',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('regions');
        Queue::assertNothingPushed();
    }

    public function test_a_grandfathered_monitor_saves_at_its_stored_region_count(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id, ['regions' => MonitorRegion::values()]);

        // A team that downgraded to Free keeps the regions it already had: an
        // edit that does not INCREASE the count must stay saveable, otherwise a
        // typo fix locks the operator out of their own monitor.
        $response = $this->putJson("/api/v1/monitors/{$monitor->id}", [
            'name' => 'API Health (renamed)',
            'regions' => MonitorRegion::values(),
        ]);

        $response->assertStatus(200);

        $fresh = $monitor->fresh();
        $this->assertSame('API Health (renamed)', $fresh->name);
        $this->assertSame(MonitorRegion::values(), $fresh->regions);
    }

    public function test_a_grandfathered_monitor_cannot_increase_its_region_count(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();
        $stored = [
            'us-east',
            'us-west',
            'eu-west',
        ];
        $monitor = $this->makeMonitor($team->id, ['regions' => $stored]);

        // Three regions are grandfathered; a fourth is a new purchase the Free
        // allowance refuses.
        $response = $this->putJson("/api/v1/monitors/{$monitor->id}", [
            'regions' => [
                ...$stored,
                'eu-central',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('regions');
        $this->assertSame($stored, $monitor->fresh()->regions);
    }

    public function test_a_scalar_regions_payload_is_a_422_not_a_500(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        // `regions` is attacker-controlled: counting a scalar would raise a
        // TypeError inside the validator and answer 500 on an authenticated
        // endpoint (the same class of bug bootstrap/app.php documents for
        // `email[]=x`).
        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'regions' => 'notanarray',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('regions');
    }

    public function test_show_masks_cross_team_monitor_as_404(): void
    {
        $this->actingAsTeamMember();

        $foreignTeam = Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign Team',
            'personal_team' => true,
        ]);
        $foreignMonitor = $this->makeMonitor($foreignTeam->id);

        $response = $this->getJson("/api/v1/monitors/{$foreignMonitor->id}");

        $response->assertStatus(404);
    }

    public function test_resource_never_leaks_auth_config_secret(): void
    {
        $team = $this->actingAsTeamMember();

        $monitor = $this->makeMonitor($team->id, [
            'auth_config' => [
                'type' => 'bearer',
                'username' => 'ops',
                'token' => 'super-secret-token',
                'password' => 'super-secret-password',
                'value' => 'super-secret-value',
            ],
        ]);

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.auth_config.type', 'bearer');
        $response->assertJsonPath('data.auth_config.username', 'ops');
        $response->assertJsonMissingPath('data.auth_config.token');
        $response->assertJsonMissingPath('data.auth_config.password');
        $response->assertJsonMissingPath('data.auth_config.value');

        // Defense in depth: no secret string survives anywhere in the body.
        $this->assertStringNotContainsString('super-secret', $response->getContent());
    }

    public function test_show_includes_measured_uptime_24h_from_checks(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        // 2 up + 1 down in the trailing window -> 2/3 = 66.67% over 24h.
        foreach ([MonitorStatus::Up, MonitorStatus::Up, MonitorStatus::Down] as $status) {
            $this->makeCheck($monitor, $status);
        }

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}");

        $response->assertStatus(200);
        $this->assertEqualsWithDelta(66.67, $response->json('data.uptime_24h'), 0.01);
    }

    public function test_show_reports_null_uptime_24h_when_a_monitor_has_no_checks(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}");

        $response->assertStatus(200);
        // A brand-new monitor has no checks: uptime is null (no data), never a
        // fabricated 0% that would read as a total breach on the client.
        $this->assertNull($response->json('data.uptime_24h'));
    }

    public function test_show_includes_real_reliability_minutes_from_checks(): void
    {
        $now = CarbonImmutable::create(2026, 8, 1, 9, 15, 0, 'UTC');
        $this->travelTo($now);

        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id, ['created_at' => $now->subHours(15)]);

        // 767 checks over the last 767 minutes with two of them down in two
        // distinct minutes: the shape production monitor
        // a276e7c5-26d5-4b53-b522-f0ce3b52d226 carried while the old
        // ratio-times-window formula reported 26.2 down minutes on 7d and
        // 112.3 on 30d.
        $rows = [];
        for ($i = 0; $i < 767; $i++) {
            $rows[] = [
                'id' => (string) Str::orderedUuid(),
                'checked_at' => $now->subMinutes($i)->format('Y-m-d H:i:s'),
                'monitor_id' => $monitor->id,
                'team_id' => $monitor->team_id,
                'region' => 'us-east',
                'status' => in_array($i, [10, 11], true) ? MonitorStatus::Down->value : MonitorStatus::Up->value,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            MonitorCheck::query()->insert($chunk);
        }

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}");

        $response->assertStatus(200);

        foreach (['7d', '30d'] as $range) {
            // assertEqualsWithDelta rather than assertSame: a whole-number
            // float round-trips through JSON as a PHP int (2.0 -> "2" -> 2),
            // which is a JSON artefact, not a wire defect.
            foreach ([
                'down' => 2.0,
                'observed' => 900.0,
                'measured' => 767.0,
                'gap' => 133.0,
            ] as $field => $expected) {
                $this->assertEqualsWithDelta(
                    $expected,
                    $response->json("data.slo_{$field}_minutes_{$range}"),
                    0.01,
                    "{$field} minutes for {$range}",
                );
            }
        }

        $response->assertJsonMissingPath('data.slo_uptime_7d');
        $response->assertJsonMissingPath('data.slo_uptime_30d');
        $response->assertJsonMissingPath('data.slo_window_minutes_7d');
        $response->assertJsonMissingPath('data.slo_window_minutes_30d');
    }

    public function test_show_reports_zero_measured_minutes_when_a_monitor_has_no_checks(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}");

        $response->assertStatus(200);

        // A brand-new monitor with a fully-elapsed window still needs a
        // representable answer: 0.0 rather than a missing key, so the client
        // can tell "nothing measured" from "measured and fine".
        foreach (['7d', '30d'] as $range) {
            $this->assertEqualsWithDelta(0.0, $response->json("data.slo_measured_minutes_{$range}"), 0.01);
            $this->assertEqualsWithDelta(0.0, $response->json("data.slo_down_minutes_{$range}"), 0.01);
        }
    }

    public function test_pause_sets_status_to_paused(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $response = $this->postJson("/api/v1/monitors/{$monitor->id}/pause");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'paused');
        $this->assertNull($monitor->fresh()->next_check_at);
    }

    public function test_update_validates_a_partial_host_edit_against_the_bound_tcp_type(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id, [
            'type' => 'tcp',
            'url' => 'db.example.com:5432',
        ]);

        // A partial edit that omits `type` must still validate the new host:port
        // as a TCP target (resolved from the bound monitor), not as a URL.
        $response = $this->putJson("/api/v1/monitors/{$monitor->id}", [
            'url' => 'db2.example.com:5433',
        ]);

        $response->assertStatus(200);
        $this->assertSame('db2.example.com:5433', $monitor->fresh()->url);
    }

    /**
     * Authenticate as a user whose current team is a freshly created team.
     */
    protected function actingAsTeamMember(): Team
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $team;
    }

    /**
     * Build a persisted monitor for the given team.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeMonitor(string $teamId, array $overrides = []): Monitor
    {
        return Monitor::create([
            'team_id' => $teamId,
            'name' => 'API Health',
            'type' => 'http',
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 60,
            'timeout_sec' => 30,
            'regions' => ['us-east'],
            'expected_status_code' => 200,
            'status' => 'active',
            'next_check_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * Record a check for the monitor at the current time (used to exercise the
     * measured-uptime computation on show).
     */
    protected function makeCheck(Monitor $monitor, MonitorStatus $status): MonitorCheck
    {
        return MonitorCheck::create([
            'id' => (string) Str::orderedUuid(),
            'checked_at' => now(),
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'us-east-1',
            'status' => $status,
            'response_ms' => 100,
        ]);
    }

    /**
     * A valid create payload targeting a public host from a single region.
     *
     * @return array<string, mixed>
     */
    public function test_store_persists_the_pinned_escalation_policy(): void
    {
        // EscalationDispatcher::resolvePolicy() reads monitors.escalation_policy_id
        // to decide who gets paged, falling back to the team's earliest-created
        // policy when it is null. A dropped pin therefore does not fail loudly:
        // it silently pages the wrong ladder during a real outage.
        Queue::fake();
        $team = $this->actingAsTeamMember();
        $policy = EscalationPolicy::create([
            'team_id' => $team->id,
            'name' => 'Critical path',
        ]);

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'escalation_policy_id' => $policy->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.escalation_policy_id', (string) $policy->id);
        $this->assertSame(
            (string) $policy->id,
            (string) Monitor::query()->latest('created_at')->first()->escalation_policy_id,
        );
    }

    public function test_store_persists_the_ai_mode(): void
    {
        // ai_mode is load-bearing, not cosmetic: SweepAiSuggestions scans the
        // fleet with whereIn('ai_mode', ['suggest','auto']) and
        // TriageAnomalyCandidate gates on AiMode::Suggest. An unvalidated (and so
        // dropped) ai_mode leaves every monitor at the `off` default, which means
        // the AI suggestion pipeline never runs for a monitor created through the
        // product, while the UI shows the operator that they enabled it.
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'ai_mode' => 'suggest',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.ai_mode', 'suggest');
        $this->assertSame(
            AiMode::Suggest,
            Monitor::query()->latest('created_at')->first()->ai_mode,
        );
    }

    public function test_store_rejects_an_unknown_ai_mode(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'ai_mode' => 'sentient',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('ai_mode');
    }

    public function test_store_rejects_an_escalation_policy_from_another_team(): void
    {
        // Pinning across tenants would page another team's responders, so the
        // rule is scoped to the acting team rather than a bare exists check.
        Queue::fake();
        $this->actingAsTeamMember();
        $otherOwner = User::factory()->create();
        $otherTeam = Team::create([
            'user_id' => $otherOwner->id,
            'name' => 'Other Co',
            'personal_team' => true,
        ]);
        $foreign = EscalationPolicy::create([
            'team_id' => $otherTeam->id,
            'name' => 'Their ladder',
        ]);

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'escalation_policy_id' => $foreign->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('escalation_policy_id');
    }

    public function test_update_can_clear_and_repin_the_escalation_policy(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();
        $policy = EscalationPolicy::create([
            'team_id' => $team->id,
            'name' => 'Standard',
        ]);
        $monitor = $this->makeMonitor($team->id, ['escalation_policy_id' => $policy->id]);

        // Clearing it must round-trip as null, not be ignored as "absent".
        $cleared = $this->putJson("/api/v1/monitors/{$monitor->id}", [
            'escalation_policy_id' => null,
        ]);
        $cleared->assertStatus(200);
        $this->assertNull($monitor->fresh()->escalation_policy_id);

        $repinned = $this->putJson("/api/v1/monitors/{$monitor->id}", [
            'escalation_policy_id' => $policy->id,
        ]);
        $repinned->assertStatus(200);
        $this->assertSame(
            (string) $policy->id,
            (string) $monitor->fresh()->escalation_policy_id,
        );
    }

    // -----------------------------------------------------------------
    // The bulk metrics[] the AI create flow submits with the monitor
    // -----------------------------------------------------------------

    public function test_store_writes_the_submitted_metrics_with_the_monitor(): void
    {
        Queue::fake();
        $team = $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'metrics' => [
                $this->metricRow('status', ['ok_values' => ['ok']]),
                $this->metricRow('render_time', [
                    'type' => MetricType::Numeric->value,
                    'threshold_direction' => ThresholdDirection::HighBad->value,
                    'warn_bound' => 400,
                    'critical_bound' => 900,
                ]),
                $this->metricRow('request_count', ['type' => MetricType::Numeric->value]),
            ],
        ]);

        $response->assertStatus(201);

        $monitor = Monitor::query()->sole();
        $metrics = MonitorMetric::query()->orderBy('display_order')->get();

        $this->assertCount(3, $metrics);
        $this->assertSame(
            ['status', 'render_time', 'request_count'],
            $metrics->pluck('key')->all(),
        );
        // display_order comes from the ARRAY INDEX, which is what reorder()
        // later rewrites; two rows claiming one position would leave it nothing
        // to resolve.
        $this->assertSame([0, 1, 2], $metrics->pluck('display_order')->all());
        // team_id is denormalized onto the metric for direct team-scoped
        // queries, and it comes from the monitor rather than the payload.
        $this->assertSame(
            [$team->id, $team->id, $team->id],
            $metrics->pluck('team_id')->map(strval(...))->all(),
        );
        $this->assertSame(
            [(string) $monitor->id],
            $metrics->pluck('monitor_id')->map(strval(...))->unique()->values()->all(),
        );
    }

    public function test_a_refused_metric_row_creates_neither_the_monitor_nor_a_metric(): void
    {
        // All or nothing. A monitor that exists while the metrics it was created
        // for do not is a monitor silently measuring nothing: the operator saw
        // the pills, the create answered 201, and the detail screen is empty.
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'metrics' => [
                $this->metricRow('status'),
                $this->metricRow('render_time'),
                $this->metricRow('bad', ['label' => str_repeat('l', 121)]),
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('metrics.2.label');
        $this->assertSame(0, Monitor::query()->count());
        $this->assertSame(0, MonitorMetric::query()->count());
    }

    public function test_a_metric_write_that_fails_takes_the_monitor_back_with_it(): void
    {
        // The 422 above cannot measure the transaction: validation refuses
        // before the controller method is entered, so nothing was ever written.
        // This one fails INSIDE the write, after the monitor row already exists,
        // which is the only shape that tells a transaction apart from two
        // sequential creates.
        Queue::fake();
        $this->actingAsTeamMember();

        MonitorMetric::creating(function (MonitorMetric $metric): void {
            if ($metric->key === 'boom') {
                throw new RuntimeException('the metric write failed');
            }
        });

        $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'metrics' => [
                $this->metricRow('status'),
                $this->metricRow('boom'),
            ],
        ])->assertStatus(500);

        $this->assertSame(0, Monitor::query()->count());
        $this->assertSame(0, MonitorMetric::query()->count());
    }

    public function test_the_first_check_is_dispatched_after_the_transaction_commits(): void
    {
        // Asserting the dispatch OCCURRED passes on the broken ordering, which
        // is the entire trap. `config/queue.php` sets `after_commit => false` on
        // every connection, so a dispatch from inside the transaction pushes the
        // payload to Redis before the row is committed; the worker re-resolves
        // the monitor by key, misses it, and deletes the job. The first check
        // then never runs, in production only, with nothing failing and the
        // suite green on SQLite's database driver.
        //
        // So the transaction DEPTH at the moment of the push is what is
        // measured. RefreshDatabase already holds one transaction open around
        // the whole test, hence the baseline rather than a literal zero.
        $queue = $this->fakeQueueRecordingTransactionDepth();
        $this->actingAsTeamMember();
        $baseline = DB::transactionLevel();

        $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'metrics' => [$this->metricRow('status')],
        ])->assertStatus(201);

        Queue::assertPushed(PerformMonitorCheck::class, 1);
        $this->assertSame([$baseline], $queue->transactionDepths);
    }

    public function test_a_bulk_row_cannot_record_a_credential_bearing_header(): void
    {
        // The denylist lives in the SHARED metricFieldRules() and resolves its
        // `source` sibling from the concrete attribute, so it has to fire under
        // the `metrics.*.` prefix too. Read off the request instead, it would
        // evaluate null here and no-op on exactly the path this plan adds:
        // `set-cookie` on a credentialled probe is an authenticated session
        // token, and a metric persists its path's value on every check forever.
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'metrics' => [
                $this->metricRow('session', [
                    'source' => MetricSource::Header->value,
                    'extraction_path' => 'set-cookie',
                ]),
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('metrics.0.extraction_path');
        $this->assertSame(0, Monitor::query()->count());
    }

    public function test_an_inverted_bound_pair_in_a_bulk_row_is_refused(): void
    {
        // The cross-field checks run from withValidator(), gated on rules()
        // declaring a BARE `metrics` key. Declare only the `metrics.*` rules and
        // the loop stays dormant forever, so a bulk row could save the inverted
        // pair the single-metric endpoint refuses.
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'metrics' => [
                $this->metricRow('render_time', [
                    'type' => MetricType::Numeric->value,
                    'threshold_direction' => ThresholdDirection::HighBad->value,
                    'warn_bound' => 900,
                    'critical_bound' => 400,
                ]),
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('metrics.0.critical_bound');
    }

    public function test_two_bulk_rows_cannot_claim_one_key(): void
    {
        // There is no persisted monitor to scope a Rule::unique against yet, so
        // `distinct` within the submitted array is the strongest check available;
        // without it the second row would violate the per-monitor unique index at
        // INSERT time and 500 instead of 422.
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'metrics' => [
                $this->metricRow('status'),
                $this->metricRow('status', ['label' => 'Status again']),
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('metrics.0.key');
    }

    public function test_more_rows_than_discovery_can_ever_propose_are_refused(): void
    {
        Queue::fake();
        $this->actingAsTeamMember();

        $rows = [];
        for ($index = 0; $index <= MetricCandidateExtractor::MAX_CANDIDATES; $index++) {
            $rows[] = $this->metricRow('metric_'.$index);
        }

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'metrics' => $rows,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('metrics');
    }

    public function test_a_banded_row_is_pinned_to_ok_and_an_unbanded_one_is_not(): void
    {
        // The pin has to be CONDITIONAL, and that is the trap:
        // validateUnmatchedBandHasAList refuses a band with all three lists
        // empty, which is every AI-proposed NUMERIC metric, so an unconditional
        // pin 422s the common case rather than pinning it.
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'metrics' => [
                $this->metricRow('status', ['ok_values' => ['ok']]),
                $this->metricRow('render_time', ['type' => MetricType::Numeric->value]),
            ],
        ]);

        $response->assertStatus(201);

        $banded = MonitorMetric::query()->where('key', 'status')->sole();
        $plain = MonitorMetric::query()->where('key', 'render_time')->sole();

        $this->assertSame(MetricBand::Ok, $banded->unmatched_band);
        $this->assertSame(['ok'], $banded->ok_values);
        $this->assertNull($plain->unmatched_band);
    }

    public function test_a_row_that_chose_its_own_unmatched_band_keeps_it(): void
    {
        // The pin fills a gap the model has no channel for; it does not overrule
        // a hand-authored bulk row that named one.
        Queue::fake();
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors', [
            ...$this->validPayload(),
            'metrics' => [
                $this->metricRow('status', [
                    'ok_values' => ['ok'],
                    'unmatched_band' => MetricBand::Warn->value,
                ]),
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame(
            MetricBand::Warn,
            MonitorMetric::query()->sole()->unmatched_band,
        );
    }

    public function test_store_still_creates_a_monitor_with_no_metrics_at_all(): void
    {
        // `metrics` is optional: the manual create flow sends none, and the
        // required label/type rules under `metrics.*` must not reach a payload
        // that carries no rows.
        Queue::fake();
        $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors', $this->validPayload())->assertStatus(201);

        $this->assertSame(1, Monitor::query()->count());
        $this->assertSame(0, MonitorMetric::query()->count());
    }

    /**
     * A valid `metrics[]` row, keyed by COLUMN name (not the analyze wire
     * vocabulary), overridable per field.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function metricRow(string $key, array $overrides = []): array
    {
        return [
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'type' => MetricType::String->value,
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => '$.'.$key,
            ...$overrides,
        ];
    }

    /**
     * Swap in a queue fake that records the transaction depth at the moment each
     * job is pushed.
     *
     * A callback passed to `Queue::assertPushed()` cannot answer this: it runs at
     * assertion time, long after the transaction closed, so it can only ever say
     * that a job was pushed and never from where.
     */
    protected function fakeQueueRecordingTransactionDepth(): QueueFake
    {
        $fake = new class($this->app, [], $this->app->make('queue')) extends QueueFake
        {
            /** @var list<int> */
            public array $transactionDepths = [];

            public function push($job, $data = '', $queue = null)
            {
                $this->transactionDepths[] = DB::transactionLevel();

                parent::push($job, $data, $queue);
            }
        };

        Queue::swap($fake);

        return $fake;
    }

    protected function validPayload(): array
    {
        return [
            'name' => 'API Health',
            // 180s is the Free tier's fastest allowed interval, so the base
            // payload is plan-valid for the default (Free) acting team; the
            // plan-enforcement tests override it to exercise the floor.
            'type' => 'http',
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 180,
            'timeout_sec' => 30,
            // One region for the same reason as the 180s interval: the base
            // payload stays plan-valid for the default (Free) acting team, and
            // the region tests override it to exercise the allowance.
            'regions' => [
                'us-east',
            ],
            'expected_status_code' => 200,
        ];
    }
}
