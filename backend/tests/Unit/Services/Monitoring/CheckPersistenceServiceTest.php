<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetricValue;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckPersistenceService;
use App\Support\Monitoring\CheckResult;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the idempotency contract of {@see CheckPersistenceService}: a relay
 * payload delivered more than once for the same `probe_run_id` must persist
 * exactly once. The oracle flagged that a bare DB unique index is not enough
 * (the hypertable promotion cannot keep it over the three-column tuple), so
 * the app-layer guard is what these tests protect: one check row, one
 * metric-value set, one consecutive-fail increment, and no double incident.
 */
class CheckPersistenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_replayed_probe_run_id_persists_exactly_once(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $service = $this->service();

        // 1. First delivery of a DOWN result: one check row, streak advances to 1.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Down));

        $this->assertSame(1, MonitorCheck::query()->count());
        $this->assertSame(1, MonitorMetricValue::query()->count());
        $this->assertSame(1, $monitor->fresh()->consecutive_fails);
        $this->assertSame(0, Incident::query()->count());

        // 2. Replay of the SAME probe_run_id is a total no-op: no second row, no
        //    second metric sample, no second increment, no evaluation.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Down));

        $this->assertSame(1, MonitorCheck::query()->count());
        $this->assertSame(1, MonitorMetricValue::query()->count());
        $this->assertSame(1, $monitor->fresh()->consecutive_fails);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_a_new_probe_run_advances_the_streak_and_opens_one_incident(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $service = $this->service();

        // 1. First DOWN result: streak reaches 1, still below the threshold.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Down));

        $this->assertSame(1, $monitor->fresh()->consecutive_fails);
        $this->assertSame(0, Incident::query()->count());

        // 2. A distinct probe run crosses the threshold: streak 2 opens exactly
        //    one incident on the consecutive-fail path.
        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'run-y', status: MonitorStatus::Down));

        $this->assertSame(2, MonitorCheck::query()->count());
        $this->assertSame(2, $monitor->fresh()->consecutive_fails);
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_a_multi_region_down_flow_opens_exactly_one_incident_with_the_pivot_populated(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $service = $this->service();

        // 1. us-east down: the streak reaches 1, still below the threshold.
        $service->persist($monitor, $this->makeResult(
            $monitor,
            probeRunId: 'us-1',
            status: MonitorStatus::Down,
            region: 'us-east',
        ));
        $this->assertSame(0, Incident::query()->count());

        // 2. eu-west down (distinct probe run + region) crosses the threshold
        //    with an atomic increment: streak 2 opens exactly one incident.
        $service->persist($monitor, $this->makeResult(
            $monitor,
            probeRunId: 'eu-1',
            status: MonitorStatus::Down,
            region: 'eu-west',
        ));

        // 3. Further downs from either region must not double-open while the
        //    incident is unresolved (the monitor-lock-serialized guard holds).
        $service->persist($monitor, $this->makeResult(
            $monitor,
            probeRunId: 'us-2',
            status: MonitorStatus::Down,
            region: 'us-east',
        ));
        $service->persist($monitor, $this->makeResult(
            $monitor,
            probeRunId: 'eu-2',
            status: MonitorStatus::Down,
            region: 'eu-west',
        ));

        $incident = Incident::query()->sole();
        $this->assertSame($monitor->id, $incident->primary_monitor_id);

        // The affected-component pivot carries the primary monitor with its
        // current health (`down`) frozen as the component status.
        $affected = $incident->monitors()->get();
        $this->assertCount(1, $affected);
        $this->assertSame($monitor->id, $affected->first()->id);
        $this->assertSame('down', $affected->first()->pivot->component_status_at_start);
        $this->assertSame('down', $affected->first()->pivot->component_status_current);
    }

    public function test_an_up_result_resets_the_consecutive_fail_streak(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $service = $this->service();

        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Down));
        $this->assertSame(1, $monitor->fresh()->consecutive_fails);

        $service->persist($monitor, $this->makeResult($monitor, probeRunId: 'run-y', status: MonitorStatus::Up));

        $this->assertSame(0, $monitor->fresh()->consecutive_fails);
        $this->assertSame(MonitorStatus::Up, $monitor->fresh()->last_status);
    }

    /**
     * Resolve the service with its real collaborators from the container.
     */
    protected function service(): CheckPersistenceService
    {
        return $this->app->make(CheckPersistenceService::class);
    }

    /**
     * Creates a monitor with one unbounded numeric metric (samples only, never
     * breaches) so metric extraction runs without muddying the incident count.
     */
    protected function makeMonitor(int $incidentThreshold): Monitor
    {
        $user = User::query()->create([
            'name' => 'Persistence Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Persistence Team',
        ]);

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => $incidentThreshold,
            'consecutive_fails' => 0,
        ]);

        $monitor->metrics()->create([
            'team_id' => $team->id,
            'label' => 'Latency',
            'key' => 'latency',
            'type' => MetricType::Numeric,
            'source' => MetricSource::JsonPath,
            'extraction_path' => 'latency',
        ]);

        return $monitor;
    }

    /**
     * Builds a CheckResult carrying a JSON body the monitor's metric can read.
     */
    protected function makeResult(
        Monitor $monitor,
        string $probeRunId,
        MonitorStatus $status,
        string $region = 'us-east-1',
    ): CheckResult {
        return new CheckResult(
            monitorId: (string) $monitor->id,
            region: $region,
            checkedAt: new DateTimeImmutable,
            status: $status,
            statusCode: $status === MonitorStatus::Up ? 200 : 503,
            responseMs: 128,
            errorMessage: null,
            timingDnsMs: 1,
            timingConnectMs: 2,
            timingTlsMs: 3,
            timingTtfbMs: 4,
            timingDownloadMs: 5,
            responseHeaders: [
                'content-type' => 'application/json',
            ],
            responseBodyPreview: '{"latency": 42}',
            probeRunId: $probeRunId,
        );
    }
}
