<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MetricBand;
use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Enums\ThresholdDirection;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetric;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the threshold-banding math and the threshold-driven incident-open
 * path: {@see ThresholdEvaluator::band()} must match the v2 boundary rules
 * exactly, and opening must stay idempotent so a sustained outage never
 * pages twice for the same unresolved incident.
 */
class ThresholdEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_band_high_bad_ok_below_warn(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::HighBad, 70.0, 80.0, 95.0);

        $this->assertSame('ok', $band->value);
    }

    public function test_band_high_bad_warn_at_or_above_warn_bound(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::HighBad, 85.0, 80.0, 95.0);

        $this->assertSame('warn', $band->value);
    }

    public function test_band_high_bad_critical_at_or_above_critical_bound(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::HighBad, 97.0, 80.0, 95.0);

        $this->assertSame('critical', $band->value);
    }

    public function test_band_low_bad_ok_above_warn(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::LowBad, 30.0, 20.0, 5.0);

        $this->assertSame('ok', $band->value);
    }

    public function test_band_low_bad_warn_at_or_below_warn_bound(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::LowBad, 15.0, 20.0, 5.0);

        $this->assertSame('warn', $band->value);
    }

    public function test_band_low_bad_critical_at_or_below_critical_bound(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::LowBad, 3.0, 20.0, 5.0);

        $this->assertSame('critical', $band->value);
    }

    public function test_band_string_critical_wins_over_warn(): void
    {
        $band = ThresholdEvaluator::bandString('down', ['up'], ['down'], ['down'], null);

        $this->assertSame(MetricBand::Critical, $band);
    }

    public function test_band_string_critical_wins_over_ok(): void
    {
        $band = ThresholdEvaluator::bandString('down', ['down'], [], ['down'], null);

        $this->assertSame(MetricBand::Critical, $band);
    }

    public function test_band_string_warn_wins_over_ok(): void
    {
        $band = ThresholdEvaluator::bandString('degraded', ['degraded'], ['degraded'], [], null);

        $this->assertSame(MetricBand::Warn, $band);
    }

    public function test_band_string_unlisted_value_takes_unmatched_band(): void
    {
        $band = ThresholdEvaluator::bandString('unknown', ['ok'], [], [], MetricBand::Warn);

        $this->assertSame(MetricBand::Warn, $band);
    }

    public function test_band_string_unlisted_value_with_null_unmatched_band_returns_null(): void
    {
        $band = ThresholdEvaluator::bandString('unknown', ['ok'], [], [], null);

        $this->assertNull($band);
    }

    public function test_band_string_all_empty_returns_null(): void
    {
        $band = ThresholdEvaluator::bandString('anything', [], [], [], null);

        $this->assertNull($band);
    }

    public function test_band_string_matches_case_insensitively(): void
    {
        $band = ThresholdEvaluator::bandString('OK', ['ok'], [], [], null);

        $this->assertSame(MetricBand::Ok, $band);
    }

    public function test_band_string_matches_value_wrapped_in_non_breaking_space(): void
    {
        $band = ThresholdEvaluator::bandString("\u{00A0}ok\u{00A0}", ['ok'], [], [], null);

        $this->assertSame(MetricBand::Ok, $band);
    }

    public function test_band_string_matches_when_configured_value_is_itself_padded(): void
    {
        $band = ThresholdEvaluator::bandString('ok', [" ok\u{00A0}"], [], [], null);

        $this->assertSame(MetricBand::Ok, $band);
    }

    /**
     * Pins the most-severe-first ordering flagged CRITICAL in planning-time
     * oracle review: an overlapping configuration must resolve to the MORE
     * severe band, so a misconfiguration produces a page rather than silence.
     */
    public function test_band_string_evaluation_order_is_most_severe_first(): void
    {
        $band = ThresholdEvaluator::bandString('x', ['x'], [], ['x'], null);

        $this->assertSame(MetricBand::Critical, $band);
    }

    public function test_band_string_normalizes_both_sides_of_the_comparison(): void
    {
        $band = ThresholdEvaluator::bandString('OK', [' ok '], [], [], MetricBand::Critical);

        $this->assertSame(MetricBand::Ok, $band);
    }

    public function test_normalize_match_value_lowercases_and_trims_ascii_whitespace(): void
    {
        $this->assertSame('ok', ThresholdEvaluator::normalizeMatchValue("  OK\t\n"));
    }

    public function test_normalize_match_value_trims_non_breaking_space(): void
    {
        $this->assertSame('ok', ThresholdEvaluator::normalizeMatchValue("\u{00A0}OK\u{00A0}"));
    }

    /**
     * The numeric metric-breach lane, pinned here because the string lane was
     * grafted onto the same loop: its title keeps saying "bound", which is the
     * correct vocabulary for a numeric threshold and the wrong one for a value
     * match.
     */
    public function test_a_numeric_metric_breach_opens_a_bound_titled_incident(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 99);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        $incident = Incident::query()->sole();
        $this->assertSame(IncidentSeverity::Critical, $incident->severity);
        $this->assertSame('cpu', $incident->trigger_metric_key);
        $this->assertSame('CPU breached critical bound', $incident->title);
    }

    /**
     * A configured string value landing in `critical_values` opens on the same
     * lane a numeric bound breach does, and the metric dedupe keeps a sustained
     * mismatch to ONE incident rather than one per check interval.
     *
     * The served value differs in case from the configured one, so this also
     * proves the comparison runs through
     * {@see ThresholdEvaluator::normalizeMatchValue()} rather than a raw
     * equality.
     */
    public function test_a_critical_string_value_opens_one_incident_and_a_second_check_opens_none(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 99);
        $this->makeStringMetric($monitor, criticalValues: ['down']);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'DOWN']);

        $incident = Incident::query()->sole();
        $this->assertSame(IncidentSeverity::Critical, $incident->severity);
        $this->assertSame(SignalSource::UserThreshold, $incident->signal_source);
        $this->assertFalse($incident->ai_owned);
        $this->assertSame('redis', $incident->trigger_metric_key);

        // 1. The title names the metric and the offending value, and never
        //    borrows the numeric "bound" vocabulary.
        $this->assertStringContainsString('Redis state', $incident->title);
        $this->assertStringContainsString('DOWN', $incident->title);
        $this->assertStringNotContainsStringIgnoringCase('bound', $incident->title);

        // 2. The next check reports the same value: the existing metric-scoped
        //    dedupe is what keeps this from paging once per interval.
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'DOWN']);

        $this->assertSame(1, Incident::query()->count());
    }

    public function test_a_warn_string_value_opens_a_warn_incident(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 99);
        $this->makeStringMetric($monitor, warnValues: ['degraded']);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'degraded']);

        $this->assertSame(IncidentSeverity::Warn, Incident::query()->sole()->severity);
    }

    /**
     * A two-tier metric is the natural shape of a health endpoint: `degraded`
     * warns, `down` pages. The metric-scoped dedupe used to swallow the second
     * half of that, because it asked only whether an incident existed and never
     * compared severities. So the incident stayed at `warn` forever and the
     * critical-only notification channels, which gate on
     * `severity === Critical`, were never told a thing.
     */
    public function test_a_critical_value_escalates_an_open_warn_incident(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 99);
        $this->makeStringMetric($monitor, warnValues: ['degraded'], criticalValues: ['down']);
        $evaluator = new ThresholdEvaluator;

        $warn = $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'degraded']);
        $this->assertSame(IncidentSeverity::Warn, Incident::query()->sole()->severity);
        $this->assertNull($warn['escalated'] ?? null);

        $outcome = $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'down']);

        // 1. Still ONE incident: escalating is not opening a second one, which
        //    would split the timeline of a single outage in two.
        $this->assertSame(1, Incident::query()->count());

        // 2. The incident itself carries critical now, which is what the
        //    critical-only channel gate reads.
        $incident = Incident::query()->sole();
        $this->assertSame(IncidentSeverity::Critical, $incident->severity);

        // 3. And the escalation is REPORTED, so the dispatcher has something to
        //    notify on. A severity raised silently in the database would page
        //    nobody, which is the defect wearing a different hat.
        $this->assertNotNull($outcome['escalated'] ?? null);
        $this->assertSame($incident->getKey(), $outcome['escalated']->getKey());
    }

    public function test_a_warn_value_does_not_de_escalate_a_critical_incident(): void
    {
        // The other direction is deliberately silent: an outage that improves
        // from `down` to `degraded` is still the same outage, and quietly
        // downgrading it would retire the critical channels mid-incident.
        $monitor = $this->makeMonitor(incidentThreshold: 99);
        $this->makeStringMetric($monitor, warnValues: ['degraded'], criticalValues: ['down']);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'down']);
        $outcome = $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'degraded']);

        $this->assertSame(IncidentSeverity::Critical, Incident::query()->sole()->severity);
        $this->assertNull($outcome['escalated'] ?? null);
    }

    /**
     * `unmatched_band` is the branch that pages on a value nobody enumerated,
     * which is the whole point of configuring it: an unrecognized state is not
     * evidence of health.
     */
    public function test_an_unlisted_string_value_opens_on_the_unmatched_band(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 99);
        $this->makeStringMetric($monitor, okValues: ['up'], unmatchedBand: MetricBand::Critical);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'wedged']);

        $this->assertSame(IncidentSeverity::Critical, Incident::query()->sole()->severity);
    }

    /**
     * An extracted string value is chosen by the monitored target and unbounded
     * (`monitor_metric_values.string_value` is `text`, and a `json_path` pointed
     * at an object yields a whole JSON blob), while `incidents.title` is
     * `varchar(200)`. PostgreSQL throws on an over-long value rather than
     * trimming it, and this insert runs AFTER the check row has committed, so an
     * untruncated title would fail the processing job on every retry while the
     * telemetry it is signaling about sits there fine. SQLite drops the width
     * entirely, so an explicit length assertion is the only thing that catches
     * this on the default suite.
     */
    public function test_a_long_string_value_is_cut_to_fit_the_incident_title_column(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 99);
        $this->makeStringMetric($monitor, okValues: ['up'], unmatchedBand: MetricBand::Critical);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate(
            $monitor,
            $this->makeCheck($monitor, MonitorStatus::Up),
            [],
            ['redis' => str_repeat('überlang ', 200)],
        );

        $title = Incident::query()->sole()->title;

        $this->assertLessThanOrEqual(200, mb_strlen($title));
        $this->assertStringContainsString('Redis state', $title);
        $this->assertStringContainsString('…', $title);
    }

    public function test_an_ok_string_value_opens_nothing(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 99);
        $this->makeStringMetric($monitor, okValues: ['up'], criticalValues: ['down']);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'up']);

        $this->assertSame(0, Incident::query()->count());
    }

    /**
     * The anti-gate: {@see MonitorMetric::alertsOnString()} is the only
     * predicate allowed to decide this, and the fixture carries
     * `threshold_direction = high_bad` exactly as every client write does
     * (`monitor_metrics_controller.dart:513` sends it for every metric type).
     * A gate on that column would page on every unconfigured string metric.
     */
    public function test_a_string_metric_with_three_empty_lists_opens_nothing(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 99);
        $metric = $this->makeStringMetric($monitor);
        $evaluator = new ThresholdEvaluator;

        $this->assertSame(ThresholdDirection::HighBad, $metric->threshold_direction);
        $this->assertFalse($metric->alertsOnString());

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'anything']);

        $this->assertSame(0, Incident::query()->count());
    }

    public function test_two_consecutive_fails_open_exactly_one_incident_and_a_third_opens_none(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $evaluator = new ThresholdEvaluator;

        // 1. Second consecutive failure crosses the threshold: opens one incident.
        $monitor->consecutive_fails = 2;
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Down), [], []);

        $this->assertSame(1, Incident::query()->count());

        // 2. A third consecutive failure must not open a second incident while
        //    the first is still unresolved.
        $monitor->consecutive_fails = 3;
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Down), [], []);

        $this->assertSame(1, Incident::query()->count());

        $incident = Incident::query()->sole();
        $this->assertSame('user_threshold', $incident->signal_source->value);
        $this->assertSame('detected', $incident->lifecycle->value);
        $this->assertFalse($incident->ai_owned);
        $this->assertNull($incident->trigger_metric_key);
    }

    public function test_a_threshold_down_opens_even_when_an_ai_incident_is_active(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $evaluator = new ThresholdEvaluator;

        // 1. An autonomous AI incident is already open on the monitor. Because
        //    the threshold dedupe is signal-scoped to non-AI incidents, this AI
        //    incident must NOT mask a later threshold-DOWN detection.
        $this->makeActiveAiIncident($monitor);

        // 2. The consecutive-fail threshold crosses: a NON-AI threshold incident
        //    opens alongside the active AI incident (it is not suppressed).
        $monitor->consecutive_fails = 2;
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Down), [], []);

        $threshold = Incident::query()->where('ai_owned', false)->sole();
        $this->assertSame(SignalSource::UserThreshold, $threshold->signal_source);
        $this->assertNull($threshold->trigger_metric_key);

        // 3. Both lanes coexist: the AI incident stays, the threshold opens once.
        $this->assertSame(1, Incident::query()->where('ai_owned', true)->count());
        $this->assertSame(2, Incident::query()->count());
    }

    public function test_create_incident_opens_a_manual_incident_without_a_check(): void
    {
        $monitor = $this->makeMonitor();
        $evaluator = new ThresholdEvaluator;

        // 1. A human files an incident directly: no check row, Manual source.
        $incident = $evaluator->createIncident(
            monitor: $monitor,
            source: SignalSource::Manual,
            check: null,
            severity: IncidentSeverity::Critical,
            title: 'Manual outage report',
            triggerMetricKey: null,
        );

        // 2. The incident carries the manual provenance and is not AI-owned.
        $this->assertSame('manual', $incident->signal_source->value);
        $this->assertFalse($incident->ai_owned);
        $this->assertSame('detected', $incident->lifecycle->value);
        $this->assertNull($incident->trigger_metric_key);
        $this->assertSame($monitor->id, $incident->primary_monitor_id);
        $this->assertSame($monitor->team_id, $incident->team_id);

        // 3. With no check, the incident stamps started_at at creation time.
        $this->assertNotNull($incident->started_at);

        // 4. The affected-component pivot is attached so the incident narrates
        //    which monitor it covers, exactly as the automated path does.
        $this->assertTrue(
            $incident->monitors()->where('monitor_id', $monitor->id)->exists(),
        );
    }

    /**
     * Creates a monitor owned by a freshly created team, with the given
     * `incident_threshold` (defaulting to {@see Monitor::DEFAULT_INCIDENT_THRESHOLD}).
     */
    protected function makeMonitor(?int $incidentThreshold = null): Monitor
    {
        $user = User::query()->create([
            'name' => 'Threshold Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Threshold Team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => $incidentThreshold ?? Monitor::DEFAULT_INCIDENT_THRESHOLD,
            'consecutive_fails' => 0,
        ]);
    }

    /**
     * Attaches a bounded numeric metric so the numeric breach lane can be
     * exercised alongside the string one.
     */
    protected function makeNumericMetric(Monitor $monitor, float $warnBound, float $criticalBound): MonitorMetric
    {
        return $monitor->metrics()->create([
            'team_id' => $monitor->team_id,
            'label' => 'CPU',
            'key' => 'cpu',
            'type' => MetricType::Numeric,
            'source' => MetricSource::JsonPath,
            'extraction_path' => 'cpu',
            'threshold_direction' => ThresholdDirection::HighBad,
            'warn_bound' => $warnBound,
            'critical_bound' => $criticalBound,
        ]);
    }

    /**
     * Attaches a string metric configured the way the client actually writes
     * one: `threshold_direction` is ALWAYS `high_bad` regardless of the value
     * lists, because the Flutter write path sends it for every metric type. A
     * fixture that left it null would let a `threshold_direction`-based gate
     * pass these tests.
     *
     * @param  list<string>  $okValues
     * @param  list<string>  $warnValues
     * @param  list<string>  $criticalValues
     */
    protected function makeStringMetric(
        Monitor $monitor,
        array $okValues = [],
        array $warnValues = [],
        array $criticalValues = [],
        ?MetricBand $unmatchedBand = null,
    ): MonitorMetric {
        return $monitor->metrics()->create([
            'team_id' => $monitor->team_id,
            'label' => 'Redis state',
            'key' => 'redis',
            'type' => MetricType::String,
            'source' => MetricSource::JsonPath,
            'extraction_path' => 'redis',
            'threshold_direction' => ThresholdDirection::HighBad,
            'ok_values' => $okValues,
            'warn_values' => $warnValues,
            'critical_values' => $criticalValues,
            'unmatched_band' => $unmatchedBand,
        ]);
    }

    /**
     * Persist an active, autonomous AI-owned incident on the monitor so the
     * signal-scoped threshold dedupe can be exercised against it.
     */
    protected function makeActiveAiIncident(Monitor $monitor): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => "Anomaly detected on {$monitor->name}",
            'impact' => IncidentImpact::Major,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::AiAnomaly,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => true,
            'trigger_metric_key' => 'response_time',
            'started_at' => now(),
        ]);
    }

    /**
     * Creates a persisted check row for the given monitor.
     */
    protected function makeCheck(Monitor $monitor, MonitorStatus $status): MonitorCheck
    {
        return MonitorCheck::query()->create([
            'id' => (string) Str::orderedUuid(),
            'checked_at' => now(),
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'us-east-1',
            'status' => $status,
        ]);
    }
}
