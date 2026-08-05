<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\IncidentSeverity;
use App\Enums\MetricBand;
use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Enums\ThresholdDirection;
use App\Jobs\PerformMonitorCheck;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetric;
use App\Models\MonitorMetricValue;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\Monitoring\ThresholdEvaluator;
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
        $samples = ['latency' => '42'];

        // 1. First delivery of a DOWN result: one check row, streak advances to 1.
        //    The samples are passed explicitly because this stage never reads a
        //    body; the metric-value count is only a meaningful replay assertion
        //    if the first delivery actually records one.
        $service->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Down),
            $samples,
        );

        $this->assertSame(1, MonitorCheck::query()->count());
        $this->assertSame(1, MonitorMetricValue::query()->count());
        $this->assertSame(1, $monitor->fresh()->consecutive_fails);
        $this->assertSame(0, Incident::query()->count());

        // 2. Replay of the SAME probe_run_id is a total no-op: no second row, no
        //    second metric sample, no second increment, no evaluation.
        $service->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Down),
            $samples,
        );

        $this->assertSame(1, MonitorCheck::query()->count());
        $this->assertSame(1, MonitorMetricValue::query()->count());
        $this->assertSame(42.0, MonitorMetricValue::query()->sole()->numeric_value);
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
     * This service NEVER reads a response body. {@see PerformMonitorCheck} is
     * the single extraction site, and it resolves the best body it has (the
     * full one, or the truncated preview when the edge filtered the full one),
     * so whatever arrives here is the whole of what was extractable.
     *
     * There used to be a second extraction here, against the 10 KiB
     * `response_body_preview`, gated on `$samples === null`. It is gone: the
     * producer can no longer signal "nothing extracted" as anything but an
     * empty array, so that branch was reachable from tests alone, and a second
     * extraction site is how the preview silently became the source for a
     * metric verified against the full body.
     *
     * The preview in this fixture DOES contain a matching value, which is what
     * makes the assertion meaningful: a re-read here would produce a row.
     */
    public function test_persistence_never_extracts_from_the_body_itself(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $service = $this->service();

        // 1. No samples argument at all: the shape a direct caller uses.
        $service->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Up),
        );

        // 2. An explicit empty set: extraction ran upstream and matched nothing.
        $service->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-y', status: MonitorStatus::Up),
            [],
        );

        $this->assertSame(2, MonitorCheck::query()->count());
        $this->assertSame(0, MonitorMetricValue::query()->count());
    }

    /**
     * A string sample records its value AND freezes the band
     * {@see ThresholdEvaluator::bandString()} computes, for the same reason the
     * numeric branch freezes {@see ThresholdEvaluator::band()}: a later edit to
     * the value lists must not rewrite what a historical check reported.
     *
     * The served value differs in case from the configured one, so the frozen
     * band also proves the comparison is normalized on both sides.
     */
    public function test_a_string_sample_freezes_its_band_at_insert(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $this->attachStringMetric($monitor, warnValues: ['degraded']);

        $this->service()->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Up),
            [
                'latency' => '99',
                'cache_state' => 'DEGRADED',
            ],
        );

        $value = MonitorMetricValue::query()->where('metric_key', 'cache_state')->sole();

        // The RAW value is stored, not the normalized one: the operator needs to
        // see what the target actually served.
        $this->assertSame('DEGRADED', $value->string_value);
        $this->assertNull($value->numeric_value);
        $this->assertSame(MetricBand::Warn, $value->band);
    }

    /**
     * The end-to-end string lane through the real persistence path: a critical
     * value opens exactly one incident, and a LATER check reporting the same
     * value opens no second one. The dedupe is the difference between one page
     * and one page per check interval.
     */
    public function test_a_critical_string_sample_opens_exactly_one_incident_across_two_checks(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $this->attachStringMetric($monitor, criticalValues: ['down']);
        $service = $this->service();
        $samples = [
            'latency' => '99',
            'cache_state' => 'down',
        ];

        $service->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Up),
            $samples,
        );

        $incident = Incident::query()->sole();
        $this->assertSame(IncidentSeverity::Critical, $incident->severity);
        $this->assertSame(SignalSource::UserThreshold, $incident->signal_source);
        $this->assertFalse($incident->ai_owned);
        $this->assertSame('cache_state', $incident->trigger_metric_key);

        // A distinct probe run carrying the same value: still one incident.
        $service->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-y', status: MonitorStatus::Up),
            $samples,
        );

        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(2, MonitorMetricValue::query()->where('metric_key', 'cache_state')->count());
    }

    /**
     * A string metric with three empty lists is INERT: it still collects its
     * sample (that is the point of a string metric), but bands nothing and
     * alerts nothing, mirroring what a null `threshold_direction` means for a
     * numeric metric. The fixture carries `threshold_direction = high_bad`
     * because the client sends it for every metric type, so a gate on that
     * column would page here.
     */
    public function test_an_inert_string_metric_records_an_unbanded_sample_and_opens_nothing(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $this->attachStringMetric($monitor);

        $this->service()->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-x', status: MonitorStatus::Up),
            [
                'latency' => '99',
                'cache_state' => 'anything at all',
            ],
        );

        $value = MonitorMetricValue::query()->where('metric_key', 'cache_state')->sole();

        $this->assertSame('anything at all', $value->string_value);
        $this->assertNull($value->band);
        $this->assertSame(0, Incident::query()->count());
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
     * Attaches a string metric shaped the way the client actually writes one:
     * `threshold_direction` is ALWAYS `high_bad` regardless of the value lists,
     * because the Flutter write path sends it for every metric type. A fixture
     * that left it null would let a `threshold_direction`-based gate pass.
     *
     * @param  list<string>  $okValues
     * @param  list<string>  $warnValues
     * @param  list<string>  $criticalValues
     */
    protected function attachStringMetric(
        Monitor $monitor,
        array $okValues = [],
        array $warnValues = [],
        array $criticalValues = [],
        ?MetricBand $unmatchedBand = null,
    ): MonitorMetric {
        return $monitor->metrics()->create([
            'team_id' => $monitor->team_id,
            'label' => 'Cache state',
            'key' => 'cache_state',
            'type' => MetricType::String,
            'source' => MetricSource::JsonPath,
            'extraction_path' => 'cache_state',
            'threshold_direction' => ThresholdDirection::HighBad,
            'ok_values' => $okValues,
            'warn_values' => $warnValues,
            'critical_values' => $criticalValues,
            'unmatched_band' => $unmatchedBand,
        ]);
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
