<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckPersistenceService;
use App\Support\Monitoring\CheckResult;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A probe the EDGE refused is not evidence about the target, and must not be
 * treated as either outcome.
 *
 * Cloudflare's `connect()` rejects a raw TCP connection to any host it serves over
 * HTTP, which is every proxied hostname. Verified live against `uptizm.com:443`
 * and `kodizm.com:443` while `github.com:443` succeeds. That failure used to land
 * as a plain `down`, which advances `consecutive_fails`, crosses
 * `incident_threshold`, opens an incident and pages a responder for a service that
 * is up.
 *
 * Counting it as a SUCCESS would be worse: resetting the streak would mask a real
 * outage underneath. So the only honest handling is no verdict at all, plus a
 * monitor-level error the operator can act on, and that is what these tests pin.
 */
class ProbeRefusalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_refused_probe_writes_no_check_at_all(): void
    {
        $monitor = $this->makeMonitor();

        app(CheckPersistenceService::class)->persist($monitor, $this->refusal($monitor));

        $this->assertSame(0, MonitorCheck::query()->count());
    }

    public function test_a_refused_probe_does_not_advance_the_failure_streak(): void
    {
        // This is the page-someone-at-3am guard. `consecutive_fails` is what
        // ThresholdEvaluator compares against `incident_threshold`.
        $monitor = $this->makeMonitor(['consecutive_fails' => 1, 'incident_threshold' => 2]);

        app(CheckPersistenceService::class)->persist($monitor, $this->refusal($monitor));

        $monitor->refresh();
        $this->assertSame(1, (int) $monitor->consecutive_fails);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_a_refused_probe_does_not_reset_the_failure_streak_either(): void
    {
        // The mirror hazard: treating a refusal as healthy would clear a streak
        // built by real failures and hide an outage in progress.
        $monitor = $this->makeMonitor(['consecutive_fails' => 3, 'last_status' => MonitorStatus::Down]);

        app(CheckPersistenceService::class)->persist($monitor, $this->refusal($monitor));

        $monitor->refresh();
        $this->assertSame(3, (int) $monitor->consecutive_fails);
        $this->assertSame(MonitorStatus::Down, $monitor->last_status);
    }

    public function test_a_refused_probe_leaves_the_health_verdict_untouched(): void
    {
        $monitor = $this->makeMonitor(['last_status' => MonitorStatus::Up]);
        $before = $monitor->last_checked_at;

        app(CheckPersistenceService::class)->persist($monitor, $this->refusal($monitor));

        $monitor->refresh();
        $this->assertSame(MonitorStatus::Up, $monitor->last_status);
        $this->assertEquals($before, $monitor->last_checked_at);
    }

    public function test_a_refused_probe_records_an_actionable_error_on_the_monitor(): void
    {
        $monitor = $this->makeMonitor();

        app(CheckPersistenceService::class)->persist($monitor, $this->refusal($monitor));

        $monitor->refresh();
        $this->assertStringContainsString('HTTP check instead', (string) $monitor->last_probe_error);
        $this->assertNotNull($monitor->last_probe_error_at);
    }

    public function test_an_over_long_refusal_message_is_truncated_not_thrown(): void
    {
        // Losing the tail of an explanation beats losing the fact that the monitor
        // is misconfigured.
        $monitor = $this->makeMonitor();

        app(CheckPersistenceService::class)->persist(
            $monitor,
            $this->refusal($monitor, str_repeat('x', 900)),
        );

        $monitor->refresh();
        $this->assertSame(255, mb_strlen((string) $monitor->last_probe_error));
    }

    public function test_a_probe_that_reaches_the_target_clears_the_error(): void
    {
        $monitor = $this->makeMonitor([
            'last_probe_error' => 'A TCP check cannot reach example.com:443',
            'last_probe_error_at' => now(),
        ]);

        app(CheckPersistenceService::class)->persist($monitor, $this->success($monitor));

        $monitor->refresh();
        $this->assertNull($monitor->last_probe_error);
        $this->assertNull($monitor->last_probe_error_at);
        $this->assertSame(MonitorStatus::Up, $monitor->last_status);
    }

    public function test_a_real_failure_still_advances_the_streak(): void
    {
        // The refusal branch must not have swallowed genuine outages.
        $monitor = $this->makeMonitor(['consecutive_fails' => 0]);

        app(CheckPersistenceService::class)->persist(
            $monitor,
            $this->success($monitor, MonitorStatus::Down),
        );

        $monitor->refresh();
        $this->assertSame(1, (int) $monitor->consecutive_fails);
        $this->assertSame(1, MonitorCheck::query()->count());
    }

    public function test_the_colo_is_persisted_so_the_region_claim_is_evidenced(): void
    {
        // `region` is an echo of the request; `colo` is where the probe ran. A
        // mis-mapped locationHint is only detectable through this column.
        $monitor = $this->makeMonitor();

        app(CheckPersistenceService::class)->persist(
            $monitor,
            $this->success($monitor, MonitorStatus::Up, colo: 'FRA'),
        );

        $this->assertSame('FRA', MonitorCheck::query()->value('colo'));
    }

    public function test_a_payload_without_a_colo_still_persists(): void
    {
        // An older worker deployment, or a payload replayed from before the field
        // existed, must not fail the whole check.
        $monitor = $this->makeMonitor();

        app(CheckPersistenceService::class)->persist(
            $monitor,
            $this->success($monitor, MonitorStatus::Up, colo: null),
        );

        $this->assertSame(1, MonitorCheck::query()->count());
        $this->assertNull(MonitorCheck::query()->value('colo'));
    }

    public function test_the_wire_contract_parses_both_new_fields(): void
    {
        $result = CheckResult::fromWorkerPayload([
            'monitor_id' => 'm1',
            'region' => 'eu-west',
            'checked_at' => '2026-07-31T10:00:00+00:00',
            'status' => 'down',
            'probe_run_id' => 'p1',
            'colo' => 'CDG',
            'probe_refused' => true,
        ]);

        $this->assertSame('CDG', $result->colo);
        $this->assertTrue($result->probeRefused);

        $legacy = CheckResult::fromWorkerPayload([
            'monitor_id' => 'm1',
            'region' => 'eu-west',
            'checked_at' => '2026-07-31T10:00:00+00:00',
            'status' => 'up',
            'probe_run_id' => 'p2',
        ]);

        $this->assertNull($legacy->colo);
        $this->assertFalse($legacy->probeRefused);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeMonitor(array $attributes = []): Monitor
    {
        $user = User::factory()->create();
        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $monitor = Monitor::query()->create(array_merge([
            'team_id' => $team->id,
            'name' => 'Proxied host',
            'type' => MonitorType::Tcp,
            'url' => 'uptizm.com:443',
            'check_interval_sec' => 180,
            'incident_threshold' => 2,
        ], array_diff_key($attributes, array_flip(['last_probe_error', 'last_probe_error_at']))));

        // The two error columns are written by a query-builder update in
        // production, so seed them the same way rather than through fill().
        $seed = array_intersect_key($attributes, array_flip(['last_probe_error', 'last_probe_error_at']));
        if ($seed !== []) {
            Monitor::query()->whereKey($monitor->id)->update($seed);
            $monitor->refresh();
        }

        return $monitor;
    }

    protected function refusal(Monitor $monitor, ?string $message = null): CheckResult
    {
        return $this->makeResult(
            $monitor,
            MonitorStatus::Down,
            refused: true,
            colo: null,
            message: $message ?? 'A TCP check cannot reach uptizm.com:443: the edge network '
                .'refuses a raw connection to a host it serves over HTTP. Monitor this '
                .'target with an HTTP check instead.',
        );
    }

    protected function success(
        Monitor $monitor,
        MonitorStatus $status = MonitorStatus::Up,
        ?string $colo = 'MIA',
    ): CheckResult {
        return $this->makeResult($monitor, $status, refused: false, colo: $colo, message: null);
    }

    protected function makeResult(
        Monitor $monitor,
        MonitorStatus $status,
        bool $refused,
        ?string $colo,
        ?string $message,
    ): CheckResult {
        return new CheckResult(
            monitorId: (string) $monitor->id,
            region: 'us-east',
            checkedAt: new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
            status: $status,
            statusCode: null,
            responseMs: $refused ? null : 12,
            errorMessage: $message,
            timingDnsMs: 0,
            timingConnectMs: 0,
            timingTlsMs: 0,
            timingTtfbMs: 0,
            timingDownloadMs: 0,
            responseHeaders: [],
            responseBodyPreview: null,
            probeRunId: 'run-'.uniqid('', true),
            colo: $colo,
            probeRefused: $refused,
        );
    }
}
