<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
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
     * The end-to-end string lane through the real persistence path, which is
     * the only place the metric streak can be measured honestly: the run is read
     * back out of `monitor_metric_values`, and this is the path that writes
     * them.
     *
     * Three checks, three verdicts. The first breach is a spike and opens
     * nothing. The second makes it `incident_threshold` consecutive samples and
     * opens exactly one incident. The third changes nothing, because the dedupe
     * is the difference between one page and one page per check interval.
     */
    public function test_a_critical_string_sample_opens_one_incident_once_the_run_is_long_enough(): void
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

        $this->assertSame(
            0,
            Incident::query()->count(),
            'one sample over the line is a spike; the down lane has always waited too',
        );

        // A distinct probe run carrying the same value completes the run.
        $service->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-y', status: MonitorStatus::Up),
            $samples,
        );

        $incident = Incident::query()->sole();
        $this->assertSame(IncidentSeverity::Critical, $incident->severity);
        $this->assertSame(SignalSource::UserThreshold, $incident->signal_source);
        $this->assertFalse($incident->ai_owned);
        $this->assertSame('cache_state', $incident->trigger_metric_key);

        // And a third: still one incident.
        $service->persist(
            $monitor,
            $this->makeResult($monitor, probeRunId: 'run-z', status: MonitorStatus::Up),
            $samples,
        );

        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(3, MonitorMetricValue::query()->where('metric_key', 'cache_state')->count());
    }

    /**
     * The recovery half of the same lane, end to end: the metric comes back and
     * the incident closes on its own.
     *
     * Worth having through the real path rather than only against the evaluator,
     * because the run is read from rows this service writes, and a resolve that
     * worked on hand-written fixtures and not on real ones would look identical
     * from the evaluator's side.
     */
    public function test_a_recovered_string_metric_closes_its_incident(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $this->attachStringMetric($monitor, okValues: ['up'], criticalValues: ['down']);
        $service = $this->service();

        foreach (['run-a', 'run-b'] as $run) {
            $service->persist(
                $monitor,
                $this->makeResult($monitor, probeRunId: $run, status: MonitorStatus::Up),
                ['latency' => '99', 'cache_state' => 'down'],
            );
        }

        $this->assertTrue(Incident::query()->sole()->lifecycle->isActive());

        foreach (['run-c', 'run-d'] as $run) {
            $service->persist(
                $monitor,
                $this->makeResult($monitor, probeRunId: $run, status: MonitorStatus::Up),
                ['latency' => '99', 'cache_state' => 'up'],
            );
        }

        $incident = Incident::query()->sole();
        $this->assertSame(IncidentStatus::Resolved, $incident->lifecycle);
        $this->assertNotNull($incident->resolved_at);
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
        string $region = 'us-east',
        bool $refused = false,
        ?DateTimeImmutable $checkedAt = null,
    ): CheckResult {
        return new CheckResult(
            monitorId: (string) $monitor->id,
            region: $region,
            checkedAt: $checkedAt ?? new DateTimeImmutable,
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

    /**
     * `incident_threshold` counts TICKS, and a tick is one scheduling round
     * across every region the monitor probes from.
     *
     * The counter used to advance once per region RESULT, so a monitor with three
     * regions gathered three increments in a single round and crossed the default
     * threshold of 2 on the first one. That made the setting mean something
     * different for every monitor depending on its region count, and it absorbed
     * no flake at all on a multi-region monitor: a ten-second blip that hit the
     * whole target paged immediately.
     */
    public function test_one_tick_across_three_regions_advances_the_streak_once(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $monitor->update(['regions' => self::THREE_REGIONS]);
        $service = $this->service();

        $this->runTick($service, $monitor, 1, MonitorStatus::Down);

        $this->assertSame(
            1,
            $monitor->fresh()->consecutive_fails,
            'three region results in one tick are one tick, not three failures',
        );
        $this->assertSame(0, Incident::query()->count(), 'the threshold of 2 is not crossed by one tick');
    }

    public function test_a_second_fully_down_tick_crosses_the_threshold(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $monitor->update(['regions' => self::THREE_REGIONS]);
        $service = $this->service();

        $this->runTick($service, $monitor, 1, MonitorStatus::Down);
        $this->runTick($service, $monitor, 2, MonitorStatus::Down);

        $this->assertSame(2, $monitor->fresh()->consecutive_fails);
        $this->assertSame(1, Incident::query()->count(), 'the second round in a row opens exactly one incident');
    }

    /**
     * The published rule, kept: one healthy region clears the streak, which is
     * what stops a single bad region from paging anybody and why no quorum is
     * claimed anywhere.
     */
    public function test_one_healthy_region_in_a_tick_clears_the_streak(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $monitor->update(['regions' => self::THREE_REGIONS]);
        $service = $this->service();

        $this->runTick($service, $monitor, 1, MonitorStatus::Down);
        $this->assertSame(1, $monitor->fresh()->consecutive_fails);

        // Tick 2: two regions down, one up. The up result lands last and resets.
        $service->persist($monitor->fresh(), $this->makeResult($monitor, probeRunId: 't2-a', status: MonitorStatus::Down, region: self::THREE_REGIONS[0], checkedAt: $this->tickTime(2)));
        $service->persist($monitor->fresh(), $this->makeResult($monitor, probeRunId: 't2-b', status: MonitorStatus::Down, region: self::THREE_REGIONS[1], checkedAt: $this->tickTime(2)));
        $service->persist($monitor->fresh(), $this->makeResult($monitor, probeRunId: 't2-c', status: MonitorStatus::Up, region: self::THREE_REGIONS[2], checkedAt: $this->tickTime(2)));

        $this->assertSame(0, $monitor->fresh()->consecutive_fails);
        $this->assertSame(0, Incident::query()->count());
    }

    /**
     * A region that reported NOTHING for a tick stops the count too. A refused
     * probe writes no check row on purpose ({@see CheckPersistenceService}'s
     * refusal path leaves the status and the streak alone), because our own edge
     * declining to probe is not evidence about the customer's endpoint.
     */
    public function test_a_tick_missing_a_region_does_not_count(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $monitor->update(['regions' => self::THREE_REGIONS]);
        $service = $this->service();

        $this->runTick($service, $monitor, 1, MonitorStatus::Down);

        // Tick 2: only two of the three regions report at all.
        $service->persist($monitor->fresh(), $this->makeResult($monitor, probeRunId: 't2-a', status: MonitorStatus::Down, region: self::THREE_REGIONS[0], checkedAt: $this->tickTime(2)));
        $service->persist($monitor->fresh(), $this->makeResult($monitor, probeRunId: 't2-b', status: MonitorStatus::Down, region: self::THREE_REGIONS[1], checkedAt: $this->tickTime(2)));

        $this->assertSame(
            1,
            $monitor->fresh()->consecutive_fails,
            'an incomplete tick cannot be the second round in a row',
        );
        $this->assertSame(0, Incident::query()->count());
    }

    /**
     * A monitor with no regions configured keeps the old per-result counting. It
     * cannot have ticks, and answering zero would silently stop it alerting,
     * which is the one outcome worse than counting too fast.
     */
    public function test_a_monitor_without_regions_still_advances_per_result(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $service = $this->service();

        $service->persist($monitor->fresh(), $this->makeResult($monitor, probeRunId: 'r1', status: MonitorStatus::Down));
        $this->assertSame(1, $monitor->fresh()->consecutive_fails);

        $service->persist($monitor->fresh(), $this->makeResult($monitor, probeRunId: 'r2', status: MonitorStatus::Down));
        $this->assertSame(2, $monitor->fresh()->consecutive_fails);
        $this->assertSame(1, Incident::query()->count());
    }

    /**
     * The regions one tick fans out to, in the order ScheduleMonitorChecks would.
     */
    protected const THREE_REGIONS = ['us-east', 'eu-west', 'ap'];

    /**
     * Persist one full tick: every configured region reporting [$status].
     */
    protected function runTick(CheckPersistenceService $service, Monitor $monitor, int $tick, MonitorStatus $status): void
    {
        foreach (self::THREE_REGIONS as $index => $region) {
            $service->persist($monitor->fresh(), $this->makeResult(
                $monitor,
                probeRunId: "t{$tick}-{$index}",
                status: $status,
                region: $region,
                checkedAt: $this->tickTime($tick),
            ));
        }
    }

    /**
     * A timestamp for tick [$n], spaced so the per-region ordering that groups
     * results into ticks is unambiguous.
     */
    protected function tickTime(int $n): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-08-05 12:00:00'))->modify("+{$n} minutes");
    }
}
