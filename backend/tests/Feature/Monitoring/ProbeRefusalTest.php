<?php

namespace Tests\Feature\Monitoring;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\Monitoring\IncidentDispatcher;
use App\Support\Monitoring\CheckResult;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
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
 *
 * There is a THIRD thing a refusal settles, added after production showed the
 * gap: an incident opened before the target went dark. No check row means the
 * recovery path never runs, so those incidents could never close, and two of
 * them sat `critical` for four days. Closing one is not a recovery and must not
 * page anyone; the second group below pins that.
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

    // ---------------------------------------------------------------------
    // A target that went dark cannot evidence the incident it opened
    // ---------------------------------------------------------------------

    public function test_a_refused_probe_closes_an_incident_it_can_no_longer_evidence(): void
    {
        // The production shape, measured 2026-08-18: openai.com and claude.ai
        // began serving a bot challenge, the probe correctly refused, and the
        // incidents each had already opened sat `detected` and `critical` for
        // FOUR DAYS. A refusal writes no check row, so the recovery path that
        // would have closed them never had an `up` reading to run on.
        $monitor = $this->makeMonitor();
        $incident = $this->openIncident($monitor);
        $this->staleCheck($monitor);

        app(CheckPersistenceService::class)->persist($monitor, $this->refusal($monitor));

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->lifecycle);
        $this->assertNotNull($incident->resolved_at);
    }

    public function test_the_close_says_it_is_not_a_recovery(): void
    {
        $monitor = $this->makeMonitor();
        $incident = $this->openIncident($monitor);
        $this->staleCheck($monitor);

        app(CheckPersistenceService::class)->persist($monitor, $this->refusal($monitor));

        $note = $incident->updates()->sole();
        $this->assertSame('system', $note->actor);
        // Internal: the public page already withholds a verdict for a stale
        // target through its own staleness rule, so there is nothing to announce.
        $this->assertFalse($note->is_public);
        // NOT an AI action. The client renders `autonomous` as an "Auto mode"
        // badge, and no model was involved in this arithmetic.
        $this->assertFalse($note->autonomous);
        $this->assertStringContainsString('no longer', $note->message);
    }

    public function test_the_close_never_routes_through_the_recovery_dispatch(): void
    {
        // The whole point: `IncidentResolved` tells a responder the service came
        // back. Nothing came back here, we simply stopped being able to look.
        //
        // Asserted on the DISPATCHER, not on the notification or the event. Both
        // of those are vacuous in this suite: `IncidentResolved` is gated on the
        // monitor's `alert_on_recover` flag, and `IncidentBroadcast` is
        // `ShouldDispatchAfterCommit` while `RefreshDatabase` holds a transaction
        // that never commits, so neither can fire whatever this code does. The
        // first version of this test wired the dispatcher back in as a mutant and
        // still passed, which is how that was caught.
        $dispatcher = Mockery::spy(IncidentDispatcher::class);
        $this->app->instance(IncidentDispatcher::class, $dispatcher);

        $monitor = $this->makeMonitor();
        $incident = $this->openIncident($monitor);
        $this->staleCheck($monitor);

        app(CheckPersistenceService::class)->persist($monitor, $this->refusal($monitor));

        // The close still happened; it just did not announce a recovery.
        $this->assertSame(IncidentStatus::Resolved, $incident->refresh()->lifecycle);
        $dispatcher->shouldNotHaveReceived('dispatch');
    }

    public function test_a_fresh_reading_keeps_the_incident_open(): void
    {
        // One refused tick is not a dark target. While a recent reading still
        // stands, the incident it opened is still evidenced and a real outage
        // underneath must not be closed out from under the responder.
        $monitor = $this->makeMonitor();
        $incident = $this->openIncident($monitor);
        $this->freshCheck($monitor);

        app(CheckPersistenceService::class)->persist($monitor, $this->refusal($monitor));

        $this->assertSame(IncidentStatus::Detected, $incident->refresh()->lifecycle);
    }

    public function test_a_multi_monitor_incident_is_left_for_a_human(): void
    {
        // One component going dark says nothing about the others on the same
        // incident, so closing it would resolve an outage on evidence that only
        // covers part of it.
        $monitor = $this->makeMonitor();
        $other = $this->makeMonitor(['name' => 'Second component', 'url' => 'other.test:443']);
        $incident = $this->openIncident($monitor);
        $incident->monitors()->attach($other->id, [
            'component_status_at_start' => MonitorStatus::Down->value,
            'component_status_current' => MonitorStatus::Down->value,
        ]);
        $this->staleCheck($monitor);

        app(CheckPersistenceService::class)->persist($monitor, $this->refusal($monitor));

        $this->assertSame(IncidentStatus::Detected, $incident->refresh()->lifecycle);
    }

    public function test_a_refusal_with_no_open_incident_writes_nothing(): void
    {
        $monitor = $this->makeMonitor();
        $this->staleCheck($monitor);

        app(CheckPersistenceService::class)->persist($monitor, $this->refusal($monitor));

        $this->assertSame(0, Incident::query()->count());
    }

    /**
     * An active incident on [$monitor], attached to it and nothing else.
     */
    protected function openIncident(Monitor $monitor): Incident
    {
        $incident = Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => $monitor->name.' is down',
            'impact' => IncidentImpact::Major,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'started_at' => now()->subDays(4),
        ]);

        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => MonitorStatus::Down->value,
            'component_status_current' => MonitorStatus::Down->value,
        ]);

        return $incident;
    }

    /**
     * The monitor's newest reading, old enough that the page would withhold it.
     *
     * The refusal fixture runs at a fixed 2026-07-31T10:00Z, and staleness is
     * measured against the moment the probe ran rather than against wall-clock
     * now, so this sits before that instant.
     */
    protected function staleCheck(Monitor $monitor): void
    {
        $this->writeCheck($monitor, new DateTimeImmutable('2026-07-20T10:00:00+00:00'));
    }

    /**
     * A reading recent enough, relative to the refusal fixture's own clock, that
     * the incident it evidences still stands.
     */
    protected function freshCheck(Monitor $monitor): void
    {
        $this->writeCheck($monitor, new DateTimeImmutable('2026-07-31T09:58:00+00:00'));
    }

    protected function writeCheck(Monitor $monitor, DateTimeImmutable $at): void
    {
        MonitorCheck::query()->create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'us-east',
            'checked_at' => $at,
            'status' => MonitorStatus::Down->value,
            'status_code' => 403,
            'response_ms' => 40,
        ]);
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
