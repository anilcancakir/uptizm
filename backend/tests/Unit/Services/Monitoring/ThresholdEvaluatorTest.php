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
use App\Models\MonitorMetricValue;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\IncidentTitle;
use App\Services\Monitoring\ThresholdEvaluator;
use DateTimeImmutable;
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

        $this->assertSame('ok', $band?->value);
    }

    public function test_a_metric_with_no_bound_at_all_bands_nothing(): void
    {
        // `ok` is a VERDICT: compared against something and found fine. With no
        // bound on either side there is nothing to compare against, and the old
        // fall-through published that as health. Measured on a live discovery
        // run, five of eight proposed metrics reached the operator with both
        // bounds null, and every one of them rendered a green `ok` dot on every
        // check: a queue could fill to five hundred jobs under a metric that
        // said it was fine.
        //
        // Null is the honest answer and it is already this class's own shape for
        // it: {@see ThresholdEvaluator::bandString()} answers null for a string
        // metric with no configured list, which is the identical situation on
        // the other type.
        foreach ([ThresholdDirection::HighBad, ThresholdDirection::LowBad] as $direction) {
            $this->assertNull(
                ThresholdEvaluator::band($direction, 500.0, null, null),
                "[{$direction->value}] with no bound asserted a band it never measured",
            );
        }
    }

    public function test_one_configured_bound_is_enough_to_earn_an_ok(): void
    {
        // The other side of the rule, and the one that keeps it from becoming
        // "never say ok": a single bound IS a comparison, so a reading that does
        // not reach it has been measured and found fine. Only the total absence
        // of a bound is unknowable.
        $this->assertSame(
            MetricBand::Ok,
            ThresholdEvaluator::band(ThresholdDirection::HighBad, 70.0, 80.0, null),
        );
        $this->assertSame(
            MetricBand::Ok,
            ThresholdEvaluator::band(ThresholdDirection::HighBad, 70.0, null, 95.0),
        );
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
     *
     * The stored English is unchanged by this PR and stays asserted verbatim: it
     * is what search, the LLM prompts and every unlocalized reader see. Beside it
     * the STRUCTURED half is now asserted too, because a writer that composed the
     * right sentence and dropped the key would leave the operator app with
     * nothing to localize from and pass every assertion above.
     */
    public function test_a_resolved_metric_incident_reopens_instead_of_opening_a_second(): void
    {
        // The same loop as the down path, on the branch the reopen fix first
        // missed: a manual resolve leaves the metric still breaching, the
        // active-incident gate clears, and the very next check opened another
        // one. This is the branch a metric-driven monitor actually uses, so it
        // is the branch an operator hits.
        $monitor = $this->makeMonitor(incidentThreshold: 1);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);
        $first = Incident::query()->sole();

        // Resolved by hand while the metric is still over its bound.
        $first->update(['lifecycle' => IncidentStatus::Resolved, 'resolved_at' => now()]);

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        $this->assertSame(1, Incident::query()->count(), 'one metric still over its bound is one incident');
        $this->assertTrue(Incident::query()->sole()->lifecycle->isActive());
    }

    public function test_a_metric_that_came_back_to_ok_opens_a_new_incident(): void
    {
        // The other half on this branch: a reading in the ok band between the
        // resolve and the next breach makes the second breach its own episode.
        $monitor = $this->makeMonitor(incidentThreshold: 1);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);
        $first = Incident::query()->sole();
        $first->update(['lifecycle' => IncidentStatus::Resolved, 'resolved_at' => now()]);

        // Back under the bound. The reading is written explicitly because
        // `evaluate()` does not persist metric values: `CheckPersistenceService`
        // does, one layer up, and it is the stored reading that says the metric
        // was seen healthy. Writing it here keeps that dependency visible rather
        // than letting this test pass for a reason production does not share.
        MonitorMetricValue::query()->create([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'check_id' => $this->makeCheck($monitor, MonitorStatus::Up)->id,
            'metric_key' => 'cpu',
            'numeric_value' => 12.0,
            'band' => MetricBand::Ok,
            'recorded_at' => now(),
        ]);

        // And over it again.
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        $this->assertSame(2, Incident::query()->count());
    }

    public function test_a_numeric_metric_breach_opens_a_bound_titled_incident(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 1);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        $incident = Incident::query()->sole();
        $this->assertSame(IncidentSeverity::Critical, $incident->severity);
        $this->assertSame('cpu', $incident->trigger_metric_key);
        $this->assertSame('CPU breached critical bound', $incident->title);

        // The critical band takes its OWN key rather than the warn key plus a
        // severity parameter: the band name is part of the sentence, and a
        // parameter would carry the English word into the Turkish render.
        $this->assertSame(IncidentTitle::METRIC_CRITICAL_BOUND, $incident->title_key);
        $this->assertSame(['metric' => 'CPU'], $incident->title_params);
    }

    /**
     * The metric lane opened on the FIRST breaching sample while the down lane
     * has always waited for `incident_threshold` of them, and a spike is not an
     * outage on either lane.
     *
     * Measured on production: one monitor's total-response-time metric landed
     * in `critical` 21 times across 105 samples in two hours, with `ok` on both
     * sides of nearly every one of them. Every isolated spike was an incident.
     */
    public function test_a_lone_metric_breach_waits_for_the_streak(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        $outcome = $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        $this->assertSame(0, Incident::query()->count(), 'one sample over a bound is a spike, not an outage');
        $this->assertNull($outcome['opened']);
    }

    public function test_a_breach_on_top_of_a_breaching_sample_opens(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        // The sample BEFORE this check, written the way
        // `CheckPersistenceService` writes it. See
        // {@see self::recordSample()} for why it is spelled out here.
        $this->recordSample($monitor, 'cpu', MetricBand::Critical);

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(IncidentSeverity::Critical, Incident::query()->sole()->severity);
    }

    /**
     * The streak is CONSECUTIVE, so one healthy reading in the middle of it
     * starts the count over. Without this a flapping metric would accumulate
     * breaches across an hour and page on the pair that happened to be counted.
     */
    public function test_an_ok_sample_breaks_the_streak(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        $this->recordSample($monitor, 'cpu', MetricBand::Critical, secondsAgo: 120);
        $this->recordSample($monitor, 'cpu', MetricBand::Ok, secondsAgo: 60);

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        $this->assertSame(0, Incident::query()->count());
    }

    /**
     * The half that did not exist at all: NOTHING closed a metric incident.
     *
     * `resolveIfRecovered()` is scoped to `trigger_metric_key IS NULL` on
     * purpose, and the only other writers of `resolved_at` are the operator
     * resolve and the orphan close. So a metric incident stayed open forever,
     * and because `hasActiveIncidentForMetric()` suppresses a second open while
     * one is active, that metric could never page again either. Measured on
     * production: an incident opened on a Redis latency bound stayed `detected`
     * for seven hours while 102 of its last 105 readings were `ok`.
     */
    public function test_a_metric_incident_closes_after_an_ok_run(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        $this->recordSample($monitor, 'cpu', MetricBand::Critical, secondsAgo: 180);
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);
        $opened = Incident::query()->sole();

        // Two readings back in the ok band, which is the same number of samples
        // the open needed.
        $this->recordSample($monitor, 'cpu', MetricBand::Ok, secondsAgo: 60);
        $this->recordSample($monitor, 'cpu', MetricBand::Ok);

        $outcome = $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 12.0], []);

        $this->assertSame($opened->getKey(), $outcome['resolved']?->getKey());

        $resolved = Incident::query()->sole();
        $this->assertSame(IncidentStatus::Resolved, $resolved->lifecycle);
        $this->assertNotNull($resolved->resolved_at);

        // The close is narrated on the public timeline like the down-lane one,
        // or the status page shows an incident that simply stops.
        $this->assertSame(1, $resolved->updates()->count());
    }

    public function test_a_short_ok_run_leaves_the_metric_incident_open(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        $this->recordSample($monitor, 'cpu', MetricBand::Critical, secondsAgo: 180);
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        // One ok reading on top of the breaching one: half a recovery.
        $this->recordSample($monitor, 'cpu', MetricBand::Ok);

        $outcome = $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 12.0], []);

        $this->assertNull($outcome['resolved']);
        $this->assertTrue(Incident::query()->sole()->lifecycle->isActive());
    }

    /**
     * The metric half of the same second-boundary defect, and it reaches further
     * than the down lane's because a metric incident is the only kind that can
     * be reopened on a reading nobody looked at.
     *
     * `resolveRecoveredMetricIncident()` stamps `resolved_at` with `now()` after
     * `CheckPersistenceService` has already written the ok samples, so the
     * reading that CAUSED the resolve is always a moment older than the resolve
     * itself. A `recorded_at >= resolved_at` query therefore found it only while
     * the two landed in one second. Past that boundary the metric would read as
     * never having recovered, and the next breach would reopen the closed
     * incident instead of opening its own.
     */
    public function test_a_metric_recovery_survives_a_second_boundary(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 1);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        // On a clock this test controls, so every stamp is a fact rather than a
        // race with the second hand.
        $this->travelTo(new DateTimeImmutable('2026-08-15 12:00:10'));
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);
        $opened = Incident::query()->sole();

        // The metric comes back. Both ok readings are stamped BEFORE the resolve
        // they trigger, which is the ordering production always has: the samples
        // are persisted, then the evaluator runs and calls `now()`.
        $this->travelTo(new DateTimeImmutable('2026-08-15 12:00:11'));
        $this->recordSample($monitor, 'cpu', MetricBand::Ok);

        $this->travelTo(new DateTimeImmutable('2026-08-15 12:00:12'));
        $this->recordSample($monitor, 'cpu', MetricBand::Ok);

        $this->travelTo(new DateTimeImmutable('2026-08-15 12:00:13'));
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 12.0], []);

        $resolved = Incident::query()->sole();
        $this->assertSame($opened->getKey(), $resolved->getKey());
        $this->assertNotNull($resolved->resolved_at);
        $this->assertTrue(
            $resolved->resolved_at->greaterThan(new DateTimeImmutable('2026-08-15 12:00:12')),
            'the fixture is only meaningful while the resolve is stamped after the readings that caused it',
        );

        // And it breaks again a minute later, inside the reopen window. It
        // recovered, so this is a second episode.
        $this->travelTo(new DateTimeImmutable('2026-08-15 12:01:13'));
        $this->recordSample($monitor, 'cpu', MetricBand::Critical);
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        $this->assertSame(
            2,
            Incident::query()->count(),
            'a metric that came back is a new outage when it breaks again, whichever second the resolve landed in',
        );
    }

    /**
     * The recovery is scoped to the incident's OWN metric. A monitor with a
     * healthy disk gauge and a breaching latency one must keep the latency
     * incident open, and the two share nothing but the monitor.
     */
    public function test_one_metric_recovering_does_not_close_another_metrics_incident(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        $this->recordSample($monitor, 'cpu', MetricBand::Critical, secondsAgo: 180);
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        // A different metric's readings are healthy; `cpu` has said nothing new.
        $this->recordSample($monitor, 'disk', MetricBand::Ok, secondsAgo: 60);
        $this->recordSample($monitor, 'disk', MetricBand::Ok);

        $outcome = $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], []);

        $this->assertNull($outcome['resolved']);
        $this->assertTrue(Incident::query()->sole()->lifecycle->isActive());
    }

    /**
     * The AI lane owns its own incidents end to end, which is why the threshold
     * lane's dedupe already excludes them
     * ({@see ThresholdEvaluator::hasActiveIncidentForMonitor()}). Closing one
     * from here would let this lane retire a detection it never made, and an
     * AI incident's `trigger_metric_key` is a signal name rather than a
     * configured metric, so the readings it would be judged on are not its own.
     */
    public function test_the_threshold_lane_never_closes_an_ai_owned_incident(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $evaluator = new ThresholdEvaluator;
        $ai = $this->makeActiveAiIncident($monitor);

        $this->recordSample($monitor, 'response_time', MetricBand::Ok, secondsAgo: 60);
        $this->recordSample($monitor, 'response_time', MetricBand::Ok);

        $outcome = $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], []);

        $this->assertNull($outcome['resolved']);
        $this->assertTrue($ai->refresh()->lifecycle->isActive());
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
        $monitor = $this->makeMonitor(incidentThreshold: 1);
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

        // 2. And the structured half carries the same two facts as parameters,
        //    display-ready: the metric LABEL rather than its key, and the value
        //    as the target actually served it (the band comparison normalizes
        //    case, the title does not, so a responder reads what was reported).
        $this->assertSame(IncidentTitle::METRIC_STRING_VALUE, $incident->title_key);
        // `assertEqualsCanonicalizing`, not `assertSame`: PostgreSQL's `jsonb` does
        // not preserve object key order (it sorts them), while SQLite stores the
        // JSON text verbatim. An identical-array assertion therefore passes on the
        // test engine and fails on the production one, which is what the
        // per-engine CI job exists to catch and did.
        $this->assertEqualsCanonicalizing(
            ['metric' => 'Redis state', 'value' => 'DOWN'],
            $incident->title_params,
        );

        // 3. The next check reports the same value: the existing metric-scoped
        //    dedupe is what keeps this from paging once per interval.
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'DOWN']);

        $this->assertSame(1, Incident::query()->count());
    }

    public function test_a_warn_string_value_opens_a_warn_incident(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 1);
        $this->makeStringMetric($monitor, warnValues: ['degraded']);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'degraded']);

        $this->assertSame(IncidentSeverity::Warn, Incident::query()->sole()->severity);
    }

    /**
     * `severity` is the operator tier and `impact` is what a customer is told,
     * and the automatic path emits only Critical and Warn. So mapping Warn onto
     * the second-loudest CUSTOMER tier left the ladder with no middle rung at
     * all: the client collapses `critical` and `major` into one red badge, which
     * made a warn-tier metric breach on a monitor still answering HTTP 200 read
     * exactly like a total outage, on the operator's dashboard and on their
     * public status page both.
     */
    public function test_a_warn_metric_breach_is_minor_customer_impact(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 1);
        $this->makeStringMetric($monitor, warnValues: ['degraded']);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), [], ['redis' => 'degraded']);

        $this->assertSame(
            IncidentImpact::Minor,
            Incident::query()->sole()->impact,
            'a warning tier is not a major customer-facing outage',
        );
    }

    /**
     * The other half of the ladder, pinned so a future edit cannot quietly
     * flatten the two tiers into one: a real down streak still reads critical.
     */
    public function test_a_down_streak_is_critical_customer_impact(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 1);
        $monitor->forceFill(['consecutive_fails' => 1])->save();
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Down), [], []);

        $this->assertSame(IncidentImpact::Critical, Incident::query()->sole()->impact);
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
        $monitor = $this->makeMonitor(incidentThreshold: 1);
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

        // 3. The title moved to the louder value, and so did the PARAMETERS a
        //    localized surface renders from. An escalation that updated only the
        //    English column would keep telling every Turkish reader about
        //    "degraded", the state this incident has already left.
        $this->assertSame('Redis state reported "down"', $incident->title);
        $this->assertSame(IncidentTitle::METRIC_STRING_VALUE, $incident->title_key);
        // Canonicalizing for the reason the string-breach test above states:
        // `jsonb` reorders keys, so only the SET of parameters is stable.
        $this->assertEqualsCanonicalizing(
            ['metric' => 'Redis state', 'value' => 'down'],
            $incident->title_params,
        );

        // 4. And the escalation is REPORTED, so the dispatcher has something to
        //    notify on. A severity raised silently in the database would page
        //    nobody, which is the defect wearing a different hat.
        $this->assertNotNull($outcome['escalated'] ?? null);
        $this->assertSame($incident->getKey(), $outcome['escalated']->getKey());
    }

    /**
     * The numeric lane is where an escalation changes the KEY rather than a
     * parameter, and it is the case the string lane above cannot cover: warn and
     * critical bounds are two separate catalogue entries, so an escalation that
     * carried the title across and left the key behind would leave every
     * localized surface announcing a bound the incident has already passed.
     */
    public function test_a_numeric_escalation_moves_the_title_key_to_the_critical_bound(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 1);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        // 1. The warn band opens the incident on the warn key.
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 85.0], []);

        $warned = Incident::query()->sole();
        $this->assertSame(IncidentSeverity::Warn, $warned->severity);
        $this->assertSame('CPU breached warn bound', $warned->title);
        $this->assertSame(IncidentTitle::METRIC_WARN_BOUND, $warned->title_key);

        // 2. The critical band escalates the same incident, and all three title
        //    columns move together.
        $outcome = $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        $this->assertSame(1, Incident::query()->count());
        $this->assertNotNull($outcome['escalated'] ?? null);

        $escalated = Incident::query()->sole();
        $this->assertSame(IncidentSeverity::Critical, $escalated->severity);
        $this->assertSame('CPU breached critical bound', $escalated->title);
        $this->assertSame(IncidentTitle::METRIC_CRITICAL_BOUND, $escalated->title_key);
        $this->assertSame(['metric' => 'CPU'], $escalated->title_params);
    }

    /**
     * The public half of an escalation, and the half that was missing.
     *
     * `severity` is the operator tier; `impact` is the customer one, and it is
     * the ONLY one a customer ever sees: {@see StatusPageAssembler} serializes
     * `impact` and no `severity` at all. So an escalation that raised severity
     * and left impact where the open had put it kept publishing "Minor" about
     * an incident whose own title said `breached critical bound`.
     *
     * Measured on production before this fix: two open incidents carried
     * `severity=critical` alongside `impact=minor`, seven hours apart, both on
     * a public status page.
     */
    public function test_an_escalation_raises_the_public_impact_with_the_severity(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 1);
        $this->makeNumericMetric($monitor, warnBound: 80.0, criticalBound: 95.0);
        $evaluator = new ThresholdEvaluator;

        // 1. The warn band opens at the warn pairing, which is the tier the
        //    ladder is supposed to start on.
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 85.0], []);
        $this->assertSame(IncidentImpact::Minor, Incident::query()->sole()->impact);

        // 2. The critical band escalates, and the customer tier travels with
        //    the operator one.
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Up), ['cpu' => 97.0], []);

        $escalated = Incident::query()->sole();
        $this->assertSame(IncidentSeverity::Critical, $escalated->severity);
        $this->assertSame(
            IncidentImpact::Critical,
            $escalated->impact,
            'the status page publishes impact, so an unraised impact understates a live outage',
        );
    }

    public function test_a_warn_value_does_not_de_escalate_a_critical_incident(): void
    {
        // The other direction is deliberately silent: an outage that improves
        // from `down` to `degraded` is still the same outage, and quietly
        // downgrading it would retire the critical channels mid-incident.
        $monitor = $this->makeMonitor(incidentThreshold: 1);
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
        $monitor = $this->makeMonitor(incidentThreshold: 1);
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
        $monitor = $this->makeMonitor(incidentThreshold: 1);
        $this->makeStringMetric($monitor, okValues: ['up'], unmatchedBand: MetricBand::Critical);
        $evaluator = new ThresholdEvaluator;

        $evaluator->evaluate(
            $monitor,
            $this->makeCheck($monitor, MonitorStatus::Up),
            [],
            ['redis' => str_repeat('überlang ', 200)],
        );

        $incident = Incident::query()->sole();
        $title = $incident->title;

        $this->assertLessThanOrEqual(200, mb_strlen($title));
        $this->assertStringContainsString('Redis state', $title);
        $this->assertStringContainsString('…', $title);

        // The structured sibling, and the reason the cut lives in the composer
        // rather than at this call site: the PERSISTED parameter is already cut,
        // so the operator app, the push and the public status page all render the
        // bounded value without re-deriving the rule. 80 characters plus the
        // one-character mark.
        $this->assertSame(IncidentTitle::METRIC_STRING_VALUE, $incident->title_key);
        $this->assertSame(81, mb_strlen($incident->title_params['value']));
        $this->assertStringEndsWith('…', $incident->title_params['value']);
    }

    public function test_an_ok_string_value_opens_nothing(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 1);
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
        $monitor = $this->makeMonitor(incidentThreshold: 1);
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

        // 2. The incident carries the manual provenance and is not AI-owned. Its
        //    `title_key` is NULL, and that null is load-bearing: it is how every
        //    reader knows a human chose these words in the language they chose
        //    them in, so no surface tries to render an operator's sentence from a
        //    catalogue.
        $this->assertSame('Manual outage report', $incident->title);
        $this->assertNull($incident->title_key);
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
            // Warn pairs with Minor, matching what `IncidentSeverity::toImpact()`
            // now produces. A fixture that pins the old pairing would keep
            // describing a state the product no longer writes.
            'impact' => IncidentImpact::Minor,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::AiAnomaly,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => true,
            'trigger_metric_key' => 'response_time',
            'started_at' => now(),
        ]);
    }

    /**
     * Persist one banded metric reading, the way `CheckPersistenceService`
     * writes it one layer up.
     *
     * Spelled out in the tests rather than produced by `evaluate()`, because
     * `evaluate()` does not persist anything: the check, its metric values and
     * their frozen bands are all written by
     * `CheckPersistenceService::persistWithinTransaction()`
     * BEFORE the evaluator is called. The streak and the recovery both read
     * that history back, so a test that skipped it would be asking the
     * evaluator a question production never asks it.
     *
     * `secondsAgo` orders the run explicitly instead of relying on insert
     * order: the query sorts on `recorded_at` and rows written in one test tick
     * would otherwise share a timestamp and leave the run's shape to the
     * tiebreak.
     */
    protected function recordSample(
        Monitor $monitor,
        string $metricKey,
        MetricBand $band,
        int $secondsAgo = 0,
    ): MonitorMetricValue {
        return MonitorMetricValue::query()->create([
            'id' => (string) Str::orderedUuid(),
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'check_id' => (string) Str::orderedUuid(),
            'metric_key' => $metricKey,
            'band' => $band,
            'recorded_at' => now()->subSeconds($secondsAgo),
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
