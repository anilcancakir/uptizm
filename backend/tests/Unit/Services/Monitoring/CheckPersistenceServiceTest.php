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
     * Metric extraction now happens in the stage that holds the FULL response
     * body, so the sample arrives here pre-extracted. The preview deliberately
     * carries a different value (42), which is what makes this assertion prove
     * the pre-extracted sample is the one that persists.
     */
    public function test_pre_extracted_samples_persist_instead_of_the_truncated_preview(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);

        $this->service()->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Up),
            ['latency' => '99'],
        );

        $value = MonitorMetricValue::query()->sole();

        $this->assertSame('latency', $value->metric_key);
        $this->assertSame(99.0, $value->numeric_value);
    }

    /**
     * The idempotency guard has to hold on the pre-extracted path too: samples
     * arrive on every delivery of the same payload, so a replay must still
     * produce one check row, one metric-value set and one streak increment.
     */
    public function test_a_replayed_probe_run_id_persists_pre_extracted_samples_exactly_once(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $service = $this->service();
        $samples = ['latency' => '99'];

        $service->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Down),
            $samples,
        );
        $service->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Down),
            $samples,
        );

        $this->assertSame(1, MonitorCheck::query()->count());
        $this->assertSame(1, MonitorMetricValue::query()->count());
        $this->assertSame(99.0, MonitorMetricValue::query()->sole()->numeric_value);
        $this->assertSame(1, $monitor->fresh()->consecutive_fails);
        $this->assertSame(0, Incident::query()->count());
    }

    /**
     * A TCP probe, a content type the edge filtered out and a worker deployment
     * older than the full-body field all reach this stage with nothing
     * pre-extracted. The truncated preview must still be read, which is the
     * behaviour that predates the split.
     */
    public function test_the_truncated_preview_stays_the_fallback_when_no_samples_arrive(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);

        $this->service()->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Up),
        );

        $this->assertSame(42.0, MonitorMetricValue::query()->sole()->numeric_value);
    }

    /**
     * An EMPTY array is not the same message as no array at all, and conflating
     * them re-opens the defect moving extraction upstream exists to close.
     *
     * Null means extraction never ran for this payload (a TCP probe, a filtered
     * content type, a worker older than the body field, a direct caller), which is
     * what the preview fallback above is for. An empty array means extraction DID
     * run against the FULL body and legitimately matched nothing. Falling back
     * there would re-read the 10 KiB truncated preview and record a value the full
     * body does not support, which is how a monitor ends up with a metric whose
     * samples come from a different, shorter body than the one it was verified on.
     *
     * The preview in this fixture DOES contain a matching value, so the fallback
     * firing would produce a row; the assertion is that it does not.
     */
    public function test_an_empty_sample_set_does_not_fall_back_to_the_truncated_preview(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);

        $this->service()->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Up),
            [],
        );

        $this->assertSame(0, MonitorMetricValue::query()->count());
    }

    /**
     * The declared metric type is re-read from the database here while the value
     * was validated against it one stage earlier, so a metric edited between the
     * two stages can pair a numeric column with a word. `(float)` would record a
     * silent zero that the evaluator then bands and can page on, so the sample is
     * dropped instead.
     */
    public function test_a_non_numeric_sample_for_a_numeric_metric_records_nothing(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);

        $this->service()->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Up),
            ['latency' => 'unavailable'],
        );

        $this->assertSame(0, MonitorMetricValue::query()->count());
    }

    /**
     * Pre-extracted samples must not sneak past the probe-refusal early return:
     * a probe the EDGE refused measured nothing about the target, so it becomes
     * no check row and no metric value.
     */
    public function test_a_refused_probe_persists_no_metric_values_even_when_samples_arrive(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);

        $this->service()->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Down, refused: true),
            ['latency' => '99'],
        );

        $this->assertSame(0, MonitorCheck::query()->count());
        $this->assertSame(0, MonitorMetricValue::query()->count());
        $this->assertSame(0, $monitor->fresh()->consecutive_fails);
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
        bool $refused = false,
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
            probeRefused: $refused,
        );
    }
}
