<?php

namespace App\Services\Monitoring;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MetricBand;
use App\Enums\MetricType;
use App\Enums\MonitorStatus;
use App\Enums\SignalSource;
use App\Enums\ThresholdDirection;
use App\Jobs\TranslateStatusPageText;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetric;
use App\Models\MonitorMetricValue;
use App\Services\Ai\AiIncidentOpener;
use Illuminate\Support\Collection;

/**
 * Opens {@see Incident} rows tagged {@see SignalSource::UserThreshold} when a
 * metric sample breaches its configured bounds or when a monitor's
 * `consecutive_fails` counter crosses `incident_threshold`.
 *
 * Pure-function banding ({@see self::band()} for numbers,
 * {@see self::bandString()} for text) keeps threshold math out of Eloquent so
 * callers can freeze the band at insert time without spinning up a full domain
 * graph.
 *
 * No sentence is spelled here. Every title this class writes comes out of
 * {@see IncidentTitle::compose()} as a triple (the English render, the catalogue
 * key, the parameters) and all three are persisted, so the row an operator reads
 * in Turkish and the English sentence search reads resolve from one catalogue
 * entry and cannot drift. The truncation rule that used to live on this class
 * moved there with it.
 */
class ThresholdEvaluator
{
    /**
     * How many checks' worth of time a resolve stays "the same outage".
     *
     * A count rather than a duration, so the window scales with the monitor's
     * own cadence: ten minutes at a 60-second interval, fifty at five minutes.
     * It is the number that answers "is this failure the one we just closed",
     * and it is the only arbitrary part of {@see self::sameOutageJustResolved()};
     * ten is a handful of checks, long enough that a resolve-and-still-broken
     * loop lands inside it and short enough that tomorrow's outage does not.
     */
    private const REOPEN_WINDOW_CHECKS = 10;

    /**
     * The author on a timeline note this evaluator writes itself.
     *
     * A constant and an English sentence at the call site rather than a
     * catalogue key, matching the auto-resolve note two methods down: this file
     * already writes "<name> recovered; incident auto-resolved." the same way,
     * and `lang/*\/incidents.php` is scanned by the title-catalogue parity test,
     * which reads every key there as a title needing a client mirror.
     */
    private const SYSTEM_AUTHOR = 'Uptizm';

    /**
     * Evaluate a completed check against the monitor's thresholds and metric
     * bounds; open an incident on a new breach and auto-resolve the monitor's
     * active down incident once it recovers.
     *
     * Returns the opened and/or resolved incident references WITHOUT
     * dispatching any notification: this runs under the caller's per-monitor
     * lock, so notification sends must happen off-lock in
     * {@see CheckPersistenceService}. Either slot is null when nothing changed.
     *
     * The two sample channels are separate because they are separate types.
     * `$metricSamples` is numbers and only numbers, which is what lets
     * {@see self::band()} take a `float`; string values travel in
     * `$stringSamples` and are matched, never compared. Neither has a default:
     * a caller that forgets a channel would silently stop alerting on half the
     * metrics a monitor has configured.
     *
     * @param  array<string, float|int|null>  $metricSamples
     * @param  array<string, string>  $stringSamples
     * @return array{opened: ?Incident, resolved: ?Incident, escalated: ?Incident}
     */
    public function evaluate(Monitor $monitor, MonitorCheck $check, array $metricSamples, array $stringSamples): array
    {
        // 1. Metric bound breaches fire first so incidents carry metric context.
        $metricBreach = $this->firstMetricBreach($monitor, $metricSamples, $stringSamples);
        if ($metricBreach !== null) {
            $active = $this->activeIncidentForMetric($monitor, $metricBreach['metric']->key);

            if ($active === null) {
                // The same outage, resolved by hand and still breaching, is
                // reopened rather than opened again. This branch is the one a
                // metric-driven monitor actually uses, so it is the one an
                // operator hits; the fix landed on the down path first and this
                // was still open behind it.
                //
                // Deliberately AHEAD of the streak gate below and not subject to
                // it. The streak exists to establish that a breach is real, and
                // a reopenable candidate has already established it: the outage
                // opened an incident once, and `sameOutageJustResolved()` has
                // proved the metric was never seen healthy since. Re-proving it
                // would mute a mistaken resolve for a further `incident_threshold`
                // checks, which is the exact failure the reopen exists to stop.
                $reopenable = $this->sameOutageJustResolved(
                    $monitor,
                    $metricBreach['metric']->key,
                );

                if ($reopenable !== null) {
                    return [
                        'opened' => $this->reopenInline($monitor, $reopenable),
                        'resolved' => null,
                        'escalated' => null,
                    ];
                }

                // A single sample over a bound is a spike, not an outage. The
                // down lane has always waited for `incident_threshold` failures
                // and this one opened on the first breach, which on a live
                // monitor meant a total-response-time metric that banded
                // `critical` 21 times in 105 samples opened an incident for
                // every one of them, `ok` on both sides.
                //
                // A short streak falls THROUGH rather than returning: this check
                // opened nothing, so the consecutive-fail lane and the resolve
                // lane below both still get their turn.
                if ($this->breachStreakMet($monitor, $check, $metricBreach['metric']->key)) {
                    $opened = $this->openIncident(
                        monitor: $monitor,
                        check: $check,
                        severity: $metricBreach['severity'],
                        title: $metricBreach['title'],
                        metricKey: $metricBreach['metric']->key,
                        titleKey: $metricBreach['title_key'],
                        titleParams: $metricBreach['title_params'],
                    );

                    return [
                        'opened' => $opened,
                        'resolved' => null,
                        'escalated' => null,
                    ];
                }
            } else {
                // The metric-scoped dedupe above used to be the whole story, so
                // a breach that arrived LOUDER than the open incident was
                // swallowed: a two-tier metric (`degraded` warns, `down` pages,
                // the natural shape of a health endpoint) opened at warn and then
                // never paged, because the critical-only channels gate on the
                // incident's own severity and nothing ever raised it.
                //
                // No streak here either, and for the reopen's reason: the
                // incident is already open, so the breach is already established,
                // and delaying an escalation delays a page on something that is
                // getting worse.
                $escalated = $this->escalateIfLouder($active, $metricBreach);

                if ($escalated !== null) {
                    return [
                        'opened' => null,
                        'resolved' => null,
                        'escalated' => $escalated,
                    ];
                }
            }
        }

        // 2. Fall back to consecutive-fail threshold for bare up/down signals.
        $opened = null;
        if ($this->shouldOpenForConsecutiveFails($monitor)
            && ! $this->hasActiveIncidentForMonitor($monitor)) {
            // 2a. The same outage, resolved by hand and never actually over,
            //     is reopened rather than opened again. See
            //     {@see self::sameOutageJustResolved()}.
            $reopenable = $this->sameOutageJustResolved($monitor);

            if ($reopenable !== null) {
                $opened = $this->reopenInline($monitor, $reopenable);
            } else {
                $composed = IncidentTitle::compose(IncidentTitle::MONITOR_DOWN, [
                    'monitor' => $monitor->name,
                ]);

                $opened = $this->openIncident(
                    monitor: $monitor,
                    check: $check,
                    severity: IncidentSeverity::Critical,
                    title: $composed['title'],
                    metricKey: null,
                    titleKey: $composed['title_key'],
                    titleParams: $composed['title_params'],
                );
            }
        }

        // 3. A healthy check auto-resolves the monitor's active down incident;
        //    the recovery slot stays null when nothing is open to resolve.
        $resolved = $check->status === MonitorStatus::Up
            ? $this->resolveIfRecovered($monitor)
            : null;

        // 3a. Then a metric incident whose metric has been reading ok for a full
        //     run. NOT gated on the check's own status, deliberately: a metric
        //     incident lives while the monitor answers 200, which is the case
        //     {@see self::recoveredSince()} already documents.
        //
        //     At most one incident resolves per check, because the outcome
        //     carries one `resolved` slot and the dispatcher notifies on it. The
        //     condition is durable, so a second one closes on the next check
        //     rather than closing here without a notification; one interval of
        //     delay costs less than a silent resolve.
        $resolved ??= $this->resolveRecoveredMetricIncident($monitor);

        return [
            'opened' => $opened,
            'resolved' => $resolved,
            'escalated' => null,
        ];
    }

    /**
     * Raise [$incident]'s severity to the breach's when the breach is louder,
     * and return the incident so the caller can notify on it. Returns `null`
     * when the breach is no louder, which is the common case: the same value
     * repeating every interval must stay silent.
     *
     * Deliberately one-directional. An outage improving from `down` to
     * `degraded` keeps its critical severity, because it is still the same
     * outage and quietly downgrading it would retire the critical channels
     * mid-incident, leaving whoever is working it without the notifications
     * they were paged on.
     *
     * @param  array{metric: MonitorMetric, severity: IncidentSeverity, title: string, title_key: string, title_params: array<string, string|int>}  $breach
     */
    protected function escalateIfLouder(Incident $incident, array $breach): ?Incident
    {
        if (! $breach['severity']->outranks($incident->severity)) {
            return null;
        }

        $incident->update([
            'severity' => $breach['severity'],
            // The customer tier travels with the operator one, because it is
            // the only one a customer ever sees: `StatusPageAssembler` puts
            // `impact` on the wire and no `severity` at all. Leaving it where
            // the open put it published "Minor" about an incident whose own
            // title already read `breached critical bound`, which is what two
            // live incidents were doing for seven hours before this line.
            //
            // Safe to derive rather than merge: `impact` is written in exactly
            // three places and all three are incident CREATION, so there is no
            // operator-pinned value here to stomp. The `impact_override` flag
            // the enum's docblock used to describe was never built.
            'impact' => $breach['severity']->toImpact(),
            // The title carries the offending value, so an escalated incident
            // that still read "reported degraded" would name a state it has
            // moved on from. The key and the parameters travel with it for the
            // same reason: a localized surface renders from those, so leaving
            // `metric_warn_bound` on a critical incident would keep telling
            // every Turkish reader about the bound it has already passed.
            'title' => $breach['title'],
            'title_key' => $breach['title_key'],
            'title_params' => $breach['title_params'],
        ]);

        return $incident->refresh();
    }

    /**
     * Auto-resolve the monitor's active DOWN incident once it has recovered.
     *
     * Recovery requires a fully healthy monitor (up last status and a cleared
     * consecutive-fail streak). The resolve is scoped strictly to the down
     * incident (`trigger_metric_key IS NULL`): a recovered site must NOT clear
     * an SSL-expiry or metric-breach incident, since an up probe does not fix
     * an expiring certificate or a slow endpoint. Idempotent: a repeated up
     * check finds no active down incident and returns null.
     *
     * @return Incident|null The resolved incident, or null when nothing recovered.
     */
    public function resolveIfRecovered(Monitor $monitor): ?Incident
    {
        // 1. Only a fully healthy monitor recovers; a monitor mid-flap keeps
        //    its incident open until the streak clears.
        if ($monitor->last_status !== MonitorStatus::Up || ($monitor->consecutive_fails ?? 0) !== 0) {
            return null;
        }

        // 2. Scope to the active down incident only (trigger_metric_key NULL).
        //    Active-ness is asked in SQL through {@see Incident::scopeActive()},
        //    which reads the terminal set off the enum; this used to load the
        //    monitor's entire incident history and filter the hydrated rows.
        //    Ordered explicitly because a bare `first()` on an unordered query
        //    lets the plan decide which of two rows wins, and the dedupe that
        //    makes "two" impossible is a different method's promise.
        $incident = Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->whereNull('trigger_metric_key')
            ->active()
            ->orderBy('started_at')
            ->orderBy('id')
            ->first();

        if ($incident === null) {
            return null;
        }

        // 3. Transition to resolved and stamp a system note on the timeline,
        //    leaving the affected-component pivot intact so the incident still
        //    narrates which monitor it covered.
        $incident->update([
            'lifecycle' => IncidentStatus::Resolved,
            'resolved_at' => now(),
        ]);

        $note = $incident->updates()->create([
            'actor' => 'system',
            'author' => 'System',
            'status' => IncidentStatus::Resolved,
            'message' => "{$monitor->name} recovered; incident auto-resolved.",
            'is_public' => true,
            'autonomous' => false,
            'display_at' => now(),
        ]);

        // 4. The note is PUBLIC, so the status page renders it like any other
        //    update and it needs the same translations. This is the write path
        //    that does not look like incident authoring and is therefore the one
        //    a wiring pass skips: without it every auto-resolved incident sits at
        //    `pending` forever in every non-default language, which is precisely
        //    the moment a reader most needs the sentence.
        //
        //    The source language is the deployment default because this sentence
        //    is composed here, in English, from the monitor's own name.
        //
        //    It enqueues INSIDE the per-monitor lock, and that is a known
        //    exception to this path's "dispatch off-lock" rule rather than an
        //    oversight: the rule protects the lock from queued notification
        //    SENDS, the alternative is threading this note out through
        //    `CheckPersistenceService` and `IncidentDispatcher`, and what happens
        //    here is one Redis push inside a ten-second lock.
        TranslateStatusPageText::fanOut($note, 'message', (string) config('app.default_locale'));

        return $incident;
    }

    /**
     * How many consecutive samples establish a metric verdict, in either
     * direction: the monitor's own `incident_threshold`, the same number the
     * consecutive-fail lane counts failures with.
     *
     * One number for open and close both, so a metric cannot be quicker to
     * alarm than it is to clear. Floored at one because a zero threshold would
     * make every run vacuously satisfied, including the empty one.
     */
    protected function verdictRunLength(Monitor $monitor): int
    {
        return max(1, (int) ($monitor->incident_threshold ?? Monitor::DEFAULT_INCIDENT_THRESHOLD));
    }

    /**
     * True when this breach is the last link of an unbroken run of
     * {@see self::verdictRunLength()} breaching samples on [$metricKey].
     *
     * The current sample is NOT read back from the table. The evaluator already
     * holds it: banding it is how this method came to be called at all, and
     * asking the database to confirm a value the caller passed in would couple
     * this decision to a write ordering it does not need. So only the samples
     * BEFORE this check are read, and this check's own row is excluded by id
     * rather than by timestamp, because a multi-region monitor writes several
     * rows on the same second.
     *
     * Bands are frozen at insert, so a run spanning a threshold edit is judged
     * by the bounds each sample was actually measured against. That is the same
     * property {@see self::recoveredSince()} relies on, and it is why history
     * can answer this at all.
     */
    protected function breachStreakMet(Monitor $monitor, MonitorCheck $check, string $metricKey): bool
    {
        $needed = $this->verdictRunLength($monitor) - 1;

        if ($needed <= 0) {
            return true;
        }

        $prior = $this->recentBands(
            monitor: $monitor,
            metricKey: $metricKey,
            limit: $needed,
            excludingCheckId: $check->id,
        );

        // An exact count, not "at least": a metric with less history than the
        // run needs has not yet been observed long enough to convict.
        return $prior->count() === $needed
            && $prior->every(fn (?MetricBand $band): bool => $band !== null && $band !== MetricBand::Ok);
    }

    /**
     * Resolve one metric-scoped incident whose metric has read `ok` for a full
     * run, and return it so the caller can notify on the recovery.
     *
     * The gap this fills: {@see self::resolveIfRecovered()} is scoped to the
     * DOWN incident (`trigger_metric_key IS NULL`) on purpose, and the only
     * other writers of `resolved_at` are the operator's own resolve and the
     * orphan close a monitor deletion triggers. So nothing closed a metric
     * incident, and because {@see self::hasActiveIncidentForMetric()} suppresses
     * a second open while one is active, the first breach a metric ever had also
     * silenced it permanently. Measured on production: an incident opened on a
     * Redis latency bound sat `detected` for seven hours while 102 of that
     * metric's last 105 readings banded `ok`.
     *
     * Two exclusions, both mirroring guards this class already applies:
     *
     * - `ai_owned` incidents belong to the autonomous lane, which owns their
     *   lifecycle end to end. Their `trigger_metric_key` is a signal name rather
     *   than a configured metric key, so the readings this method would judge
     *   them on are not theirs to begin with.
     * - An SSL-expiry incident carries a marker key with no metric values behind
     *   it, so it falls out through the run's exact-count rule rather than
     *   through a special case. A renewed certificate is `PerformSslCheck`'s own
     *   question to answer, and it is named in backticks rather than a `{@see}`
     *   because Pint's `fully_qualified_strict_types` fixer turns the latter
     *   into a real `use App\Jobs\...` at the top of a domain service.
     */
    protected function resolveRecoveredMetricIncident(Monitor $monitor): ?Incident
    {
        $length = $this->verdictRunLength($monitor);

        $incident = Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->where('ai_owned', false)
            ->whereNotNull('trigger_metric_key')
            ->active()
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->first(fn (Incident $incident): bool => $this->metricRunIsOk(
                $monitor,
                (string) $incident->trigger_metric_key,
                $length,
            ));

        if ($incident === null) {
            return null;
        }

        // 1. Transition and stamp the timeline, exactly as the down-lane resolve
        //    does: directly, because the per-monitor lock is already held by
        //    `CheckPersistenceService` and asking the write service for it would
        //    deadlock ({@see self::reopenInline()} records the measurement).
        $incident->update([
            'lifecycle' => IncidentStatus::Resolved,
            'resolved_at' => now(),
        ]);

        // 2. Named by the metric rather than the monitor, because the monitor
        //    never went down: the sentence has to say what actually recovered or
        //    it reads as an outage note on a service that never had one. The
        //    label is the operator's own wording and the key is the fallback for
        //    a metric deleted out from under its incident.
        $label = $monitor->metrics()
            ->where('key', $incident->trigger_metric_key)
            ->value('label') ?? $incident->trigger_metric_key;

        // 3. Public and fanned out for translation for the reason the down-lane
        //    note is: a status page renders it like any other update, and an
        //    untranslated one sits at `pending` in every non-default language.
        $note = $incident->updates()->create([
            'actor' => 'system',
            'author' => 'System',
            'status' => IncidentStatus::Resolved,
            'message' => "{$label} returned to its normal range on {$monitor->name}; incident auto-resolved.",
            'is_public' => true,
            'autonomous' => false,
            'display_at' => now(),
        ]);

        TranslateStatusPageText::fanOut($note, 'message', (string) config('app.default_locale'));

        return $incident;
    }

    /**
     * True when [$metricKey]'s most recent `$length` samples all banded `ok`.
     *
     * Unlike {@see self::breachStreakMet()} this counts the current check's own
     * row, and the asymmetry is the point: a recovery is about a metric that is
     * NOT breaching, so it never reached the breach code and the evaluator holds
     * no banded sample for it. The table is the only place that reading lives.
     *
     * A null band does not count as ok. Null means the metric was recorded with
     * nothing to judge it against (no direction, or a string metric with no
     * configured vocabulary), and an unjudged reading is not evidence of health,
     * which is the rule {@see self::band()} already applies one layer down.
     */
    protected function metricRunIsOk(Monitor $monitor, string $metricKey, int $length): bool
    {
        $recent = $this->recentBands($monitor, $metricKey, $length);

        return $recent->count() === $length
            && $recent->every(fn (?MetricBand $band): bool => $band === MetricBand::Ok);
    }

    /**
     * The `$limit` most recent frozen bands for [$metricKey], newest first.
     *
     * Ordered on `recorded_at` with `id` as the tiebreak, because a multi-region
     * monitor writes one row per region on the same timestamp and an unordered
     * tail would make the run's shape depend on the plan.
     *
     * @return Collection<int, MetricBand|null>
     */
    protected function recentBands(
        Monitor $monitor,
        string $metricKey,
        int $limit,
        ?string $excludingCheckId = null,
    ): Collection {
        return MonitorMetricValue::query()
            ->where('monitor_id', $monitor->id)
            ->where('metric_key', $metricKey)
            ->when(
                $excludingCheckId !== null,
                fn ($query) => $query->where('check_id', '!=', $excludingCheckId),
            )
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['band'])
            ->map(fn (MonitorMetricValue $value): ?MetricBand => $value->band);
    }

    /**
     * Find the first metric whose sample lands in warn or critical, on either
     * channel.
     *
     * The title travels out with the breach rather than being composed by the
     * caller, because the two lanes name their breach differently: a numeric
     * metric breached a BOUND, while a string metric simply reported a value
     * somebody listed as bad. Reusing the numeric phrasing for a value match
     * would describe arithmetic that never happened. Each lane therefore picks
     * its own {@see IncidentTitle} key and carries the whole composed triple
     * out, so both the escalation path and the open path persist the same three
     * columns without re-deciding anything.
     *
     * @param  array<string, float|int|null>  $samples
     * @param  array<string, string>  $stringSamples
     * @return array{metric: MonitorMetric, severity: IncidentSeverity, title: string, title_key: string, title_params: array<string, string|int>}|null
     */
    protected function firstMetricBreach(Monitor $monitor, array $samples, array $stringSamples): ?array
    {
        foreach ($monitor->metrics as $metric) {
            $breach = $metric->type === MetricType::String
                ? $this->stringBreach($metric, $stringSamples)
                : $this->numericBreach($metric, $samples);

            if ($breach !== null) {
                return $breach;
            }
        }

        return null;
    }

    /**
     * The numeric lane: a sample banded against `warn_bound` / `critical_bound`
     * in the metric's declared direction. A metric with no direction has no
     * bounds to breach and is skipped.
     *
     * @param  array<string, float|int|null>  $samples
     * @return array{metric: MonitorMetric, severity: IncidentSeverity, title: string, title_key: string, title_params: array<string, string|int>}|null
     */
    protected function numericBreach(MonitorMetric $metric, array $samples): ?array
    {
        if ($metric->threshold_direction === null) {
            return null;
        }

        $sample = $samples[$metric->key] ?? null;
        if ($sample === null) {
            return null;
        }

        $band = self::band(
            direction: $metric->threshold_direction,
            value: (float) $sample,
            warnBound: $metric->warn_bound !== null ? (float) $metric->warn_bound : null,
            criticalBound: $metric->critical_bound !== null ? (float) $metric->critical_bound : null,
        );

        $severity = $this->severityFor($band);

        if ($severity === null) {
            return null;
        }

        // The band picks between two keys rather than parameterizing one with a
        // band name: a `:severity` placeholder would carry the English word
        // "critical" into the Turkish sentence and leave it half translated.
        // Only warn and critical reach this line, since any other band already
        // returned above.
        $titleKey = $band === MetricBand::Critical
            ? IncidentTitle::METRIC_CRITICAL_BOUND
            : IncidentTitle::METRIC_WARN_BOUND;

        return [
            'metric' => $metric,
            'severity' => $severity,
            // compose() returns exactly the three columns a writer persists, so
            // spreading it keeps this array and the incident row in one shape.
            ...IncidentTitle::compose($titleKey, [
                'metric' => $metric->label,
            ]),
        ];
    }

    /**
     * The string lane: a sample matched against the metric's three configured
     * value lists.
     *
     * Gated on {@see MonitorMetric::alertsOnString()} and on nothing else.
     * NOT on `threshold_direction`, which every string metric carries as
     * `high_bad` whether or not anything is configured, so gating there would
     * page on the first sample of every string metric ever created.
     *
     * @param  array<string, string>  $stringSamples
     * @return array{metric: MonitorMetric, severity: IncidentSeverity, title: string, title_key: string, title_params: array<string, string|int>}|null
     */
    protected function stringBreach(MonitorMetric $metric, array $stringSamples): ?array
    {
        if (! $metric->alertsOnString()) {
            return null;
        }

        $sample = $stringSamples[$metric->key] ?? null;
        if ($sample === null) {
            return null;
        }

        $severity = $this->severityFor(self::bandString(
            value: $sample,
            okValues: $metric->ok_values,
            warnValues: $metric->warn_values,
            criticalValues: $metric->critical_values,
            unmatchedBand: $metric->unmatched_band,
        ));

        if ($severity === null) {
            return null;
        }

        return [
            'metric' => $metric,
            'severity' => $severity,
            // The value names what the target actually said, so a responder
            // reading only the title knows it. It is attacker-influenced and
            // unbounded, and compose() is what cuts it before it becomes a
            // parameter; nothing on this side re-applies that rule.
            ...IncidentTitle::compose(IncidentTitle::METRIC_STRING_VALUE, [
                'metric' => $metric->label,
                'value' => $sample,
            ]),
        ];
    }

    /**
     * The incident severity a band warrants, or null when the band is not a
     * breach. `ok` and an absent band (an inert configuration) both mean
     * nothing to open.
     */
    protected function severityFor(?MetricBand $band): ?IncidentSeverity
    {
        return match ($band) {
            MetricBand::Critical => IncidentSeverity::Critical,
            MetricBand::Warn => IncidentSeverity::Warn,
            MetricBand::Ok, null => null,
        };
    }

    /**
     * Pure-function banding: compute a {@see MetricBand} from a numeric
     * sample and its bounds, respecting direction.
     */
    public static function band(
        ThresholdDirection $direction,
        float $value,
        ?float $warnBound,
        ?float $criticalBound,
    ): ?MetricBand {
        // A metric with no bound on either side has nothing to be compared
        // against, and `ok` is a verdict rather than a default: it says a
        // reading was measured against a threshold and found fine. Falling
        // through to it here published "healthy" about a number nobody had set
        // an expectation for, and measured on a live discovery run five of eight
        // proposed metrics arrived in exactly that state, each rendering a green
        // dot on every check. Null is the same answer {@see self::bandString()}
        // already gives an unconfigured string metric.
        if ($warnBound === null && $criticalBound === null) {
            return null;
        }

        if ($direction === ThresholdDirection::HighBad) {
            if ($criticalBound !== null && $value >= $criticalBound) {
                return MetricBand::Critical;
            }
            if ($warnBound !== null && $value >= $warnBound) {
                return MetricBand::Warn;
            }

            return MetricBand::Ok;
        }

        if ($criticalBound !== null && $value <= $criticalBound) {
            return MetricBand::Critical;
        }
        if ($warnBound !== null && $value <= $warnBound) {
            return MetricBand::Warn;
        }

        return MetricBand::Ok;
    }

    /**
     * Pure-function banding for a string-valued metric: compare a normalized
     * sample against three configured value lists.
     *
     * Evaluates critical, then warn, then ok, then `$unmatchedBand`, mirroring
     * {@see self::band()}'s own most-severe-first fail-safe at an overlapping
     * numeric configuration: when a value is (mis)configured into more than
     * one list, resolving to the MORE severe band means the misconfiguration
     * pages someone instead of staying silent. Returns null only in the inert
     * case: all three lists are empty AND `$unmatchedBand` is null, mirroring
     * what a null `threshold_direction` means for a numeric metric.
     *
     * @param  list<string>  $okValues
     * @param  list<string>  $warnValues
     * @param  list<string>  $criticalValues
     */
    public static function bandString(
        string $value,
        array $okValues,
        array $warnValues,
        array $criticalValues,
        ?MetricBand $unmatchedBand,
    ): ?MetricBand {
        $normalized = self::normalizeMatchValue($value);

        if (self::matchesAny($normalized, $criticalValues)) {
            return MetricBand::Critical;
        }
        if (self::matchesAny($normalized, $warnValues)) {
            return MetricBand::Warn;
        }
        if (self::matchesAny($normalized, $okValues)) {
            return MetricBand::Ok;
        }

        return $unmatchedBand;
    }

    /**
     * True when the normalized sample equals any configured value, once each
     * configured value is normalized the same way.
     *
     * @param  list<string>  $values
     */
    protected static function matchesAny(string $normalizedValue, array $values): bool
    {
        foreach ($values as $configured) {
            if (self::normalizeMatchValue($configured) === $normalizedValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a string metric value or a configured match value so both
     * sides of {@see self::bandString()}'s comparison agree.
     *
     * Case-folds with `mb_strtolower` (not `strtolower`, which is byte-wise
     * and corrupts non-ASCII), then strips leading and trailing Unicode
     * whitespace INCLUDING U+00A0 (a non-breaking space that plain `trim()`
     * does not treat as whitespace and that `MetricExtractor::extractXPath()`
     * can hand us verbatim from a rendered page). The charlist is passed via
     * regex rather than `trim()`, because `trim()` operates byte-wise and
     * would strip only one byte of a multibyte character sitting at the
     * boundary. Turkish dotted capital `İ` still does not fold to ASCII `i`
     * under `mb_strtolower`; that is accepted, not worked around, since no
     * locale-specific casing was requested.
     */
    public static function normalizeMatchValue(string $value): string
    {
        $trimmed = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value);

        return mb_strtolower($trimmed);
    }

    /**
     * True when the monitor already has an unresolved incident tagged with
     * the given trigger metric, guarding against duplicate opens on repeated
     * breaches of the same metric.
     */
    protected function hasActiveIncidentForMetric(Monitor $monitor, string $metricKey): bool
    {
        return $this->activeIncidentForMetric($monitor, $metricKey) !== null;
    }

    /**
     * The monitor's active incident for [$metricKey], or `null`.
     *
     * Returns the incident rather than a bool because escalation needs its
     * current severity to compare against; {@see hasActiveIncidentForMetric}
     * stays as the yes/no wrapper for the callers that only ask that.
     */
    protected function activeIncidentForMetric(Monitor $monitor, string $metricKey): ?Incident
    {
        return Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->where('trigger_metric_key', $metricKey)
            ->active()
            ->orderBy('started_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * True when the monitor already has an active NON-AI incident, guarding the
     * consecutive-fail fallback against opening a duplicate.
     *
     * Deliberately scoped to non-AI-owned incidents: an autonomous AI incident
     * lives in a separate detection lane and must NOT mask a threshold-DOWN, so
     * a threshold breach still opens even while an AI incident is active. The
     * mirror of this scoping lives in
     * {@see AiIncidentOpener::open()}, which dedupes only
     * against active AI incidents; the two lanes are independent so neither
     * source can suppress the other.
     */
    protected function hasActiveIncidentForMonitor(Monitor $monitor): bool
    {
        return Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->where('ai_owned', false)
            ->active()
            ->exists();
    }

    /**
     * The incident this failure belongs to, when it belongs to one already.
     *
     * A manual resolve on a monitor that is still failing leaves both open-gates
     * satisfied: `consecutive_fails` is untouched and there is no longer an
     * active incident. Measured on the running system, the very next check
     * therefore opened a brand new incident, so an operator clicking resolve ten
     * times on something still down produced ten incidents, and with autonomous
     * updates enabled, twenty model calls and ten customer-facing posts.
     *
     * Two conditions, and both matter:
     *
     * The resolve is RECENT, inside a window of ten checks' worth of time. The
     * window is what answers "is this the same outage", and deriving it from the
     * monitor's own cadence rather than fixing a number of minutes means it
     * scales with how closely the thing is watched: ten minutes at a 60-second
     * cadence, fifty at five minutes. Past it, a fresh failure is a fresh
     * episode and gets its own incident, timeline and postmortem.
     *
     * And the monitor was never SEEN HEALTHY in between. A recovery followed by
     * a new failure is genuinely a second outage even inside the window, and the
     * auto-resolve that fired on the recovery already closed the first one
     * properly.
     *
     * Reopening rather than staying silent is deliberate. A mistaken resolve
     * during a real outage must not mute it: the reopen re-dispatches the same
     * page a fresh open would, so the responders are still called, and the
     * autonomous-update path's per-stage guard stops it announcing "we are
     * investigating" to customers a second time about a moment it already
     * announced.
     */
    protected function sameOutageJustResolved(Monitor $monitor, ?string $metricKey = null): ?Incident
    {
        $cadence = $monitor->check_interval_sec ?? Monitor::DEFAULT_CHECK_INTERVAL_SEC;
        $window = now()->subSeconds(self::REOPEN_WINDOW_CHECKS * (int) $cadence);

        $candidate = Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->where('ai_owned', false)
            ->when(
                $metricKey === null,
                fn ($query) => $query->whereNull('trigger_metric_key'),
                fn ($query) => $query->where('trigger_metric_key', $metricKey),
            )
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', $window)
            ->latest('resolved_at')
            ->first();

        if ($candidate === null) {
            return null;
        }

        return $this->recoveredSince($monitor, $candidate, $metricKey) ? null : $candidate;
    }

    /**
     * Whether the thing that broke was seen working again since [$candidate]
     * was resolved.
     *
     * The two branches ask different questions of different tables, and neither
     * substitutes for the other. A down incident recovers when a check comes
     * back `up`. A metric incident recovers when a READING lands in the ok band,
     * which a check's own status cannot answer: the monitor stays `up` through
     * the whole thing, because a service reporting `degraded` in a healthy 200
     * is exactly the case metric incidents exist for.
     */
    protected function recoveredSince(Monitor $monitor, Incident $candidate, ?string $metricKey): bool
    {
        if ($metricKey === null) {
            return MonitorCheck::query()
                ->where('monitor_id', $monitor->id)
                ->where('checked_at', '>=', $candidate->resolved_at)
                ->where('status', MonitorStatus::Up)
                ->exists();
        }

        return MonitorMetricValue::query()
            ->where('monitor_id', $monitor->id)
            ->where('metric_key', $metricKey)
            ->where('recorded_at', '>=', $candidate->resolved_at)
            ->where('band', MetricBand::Ok)
            ->exists();
    }

    /**
     * Reopen an incident from inside the check pipeline, without the write
     * service.
     *
     * `IncidentWriteService::reopen()` is the obvious call and it deadlocks:
     * both it and `CheckPersistenceService` key their monitor lock on
     * `check-persist-monitor:{id}`, this evaluator runs inside the second one,
     * and asking for the first blocks for the full ten-second wait and then
     * fails with nothing to say. The first attempt did exactly that, and a
     * ten-second empty failure is how it announced itself.
     *
     * So the transition is written here, the way {@see self::resolveIfRecovered()}
     * writes its own: directly, because the lock is already held. The two are
     * mirror images and read as a pair.
     *
     * The note is PUBLIC and fanned out for translation for the same reason the
     * auto-resolve note is: a status page renders it like any other update, and
     * an untranslated one sits at `pending` in every non-default language.
     */
    protected function reopenInline(Monitor $monitor, Incident $incident): Incident
    {
        $incident->update([
            'lifecycle' => IncidentStatus::Investigating,
            'resolved_at' => null,
        ]);

        $note = $incident->updates()->create([
            'actor' => 'system',
            'author' => self::SYSTEM_AUTHOR,
            'status' => IncidentStatus::Investigating,
            'message' => "{$monitor->name} is still failing; the incident was reopened.",
            'is_public' => true,
            'autonomous' => false,
            'display_at' => now(),
        ]);

        TranslateStatusPageText::fanOut($note, 'message', (string) config('app.default_locale'));

        return $incident->fresh() ?? $incident;
    }

    /**
     * Falls back to {@see Monitor::DEFAULT_INCIDENT_THRESHOLD} when the
     * monitor has no explicit threshold so a freshly-created monitor still
     * opens incidents on sustained failure.
     */
    protected function shouldOpenForConsecutiveFails(Monitor $monitor): bool
    {
        $threshold = $monitor->incident_threshold ?? Monitor::DEFAULT_INCIDENT_THRESHOLD;

        return ($monitor->consecutive_fails ?? 0) >= $threshold;
    }

    /**
     * Open the automated threshold-driven incident, tagged
     * {@see SignalSource::UserThreshold} and never AI-owned (AI signal
     * detection is gated off in this port).
     *
     * Thin adapter over {@see self::createIncident()}: the automated path
     * always has a {@see MonitorCheck} to source `started_at` from, so the
     * generalized creator's nullable-check and provenance parameters are
     * pinned to the values this evaluator has always used.
     *
     * @param  string  $title  The English render from {@see IncidentTitle::compose()}.
     * @param  string|null  $titleKey  Its catalogue key, so a localized surface can re-render it.
     * @param  array<string, string|int>  $titleParams  Its display-ready parameters.
     */
    protected function openIncident(
        Monitor $monitor,
        MonitorCheck $check,
        IncidentSeverity $severity,
        string $title,
        ?string $metricKey,
        ?string $titleKey = null,
        array $titleParams = [],
    ): Incident {
        return $this->createIncident(
            monitor: $monitor,
            source: SignalSource::UserThreshold,
            check: $check,
            severity: $severity,
            title: $title,
            triggerMetricKey: $metricKey,
            aiOwned: false,
            titleKey: $titleKey,
            titleParams: $titleParams,
        );
    }

    /**
     * Persist an incident for the monitor and attach it to the
     * affected-component pivot, generalized over its detection provenance.
     *
     * This is the single creation seam shared by the automated evaluator
     * (via {@see self::openIncident()}, passing {@see SignalSource::UserThreshold}
     * plus the triggering check) and by operator-initiated writes (passing
     * {@see SignalSource::Manual} plus a null check). When no check is given
     * the incident stamps `started_at` at creation time.
     *
     * @param  Monitor  $monitor  The monitor the incident is primarily about.
     * @param  SignalSource  $source  Who noticed first (threshold, AI, or human).
     * @param  MonitorCheck|null  $check  The triggering check, or null for a manual open.
     * @param  IncidentSeverity  $severity  Severity, projected to the public impact tier.
     * @param  string  $title  Human-facing incident title, English on a composed path.
     * @param  string|null  $triggerMetricKey  Metric key when a bound breach triggered it.
     * @param  bool  $aiOwned  True when an AI detector owns the incident lifecycle.
     * @param  string|null  $titleKey  The {@see IncidentTitle} key `$title` was composed from,
     *                                 or null when a human authored the title. That null is what
     *                                 makes `title_key IS NULL` readable as "authored", so the
     *                                 operator path leaves it alone rather than inventing a key.
     * @param  array<string, string|int>  $titleParams  The display-ready parameters that key
     *                                                  renders with; empty on an authored title.
     */
    public function createIncident(
        Monitor $monitor,
        SignalSource $source,
        ?MonitorCheck $check,
        IncidentSeverity $severity,
        string $title,
        ?string $triggerMetricKey = null,
        bool $aiOwned = false,
        ?string $titleKey = null,
        array $titleParams = [],
    ): Incident {
        // 1. Persist the incident with the denormalized primary-monitor hint.
        //    A manual open has no check, so start-time falls back to now.
        $incident = Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => $title,
            'title_key' => $titleKey,
            // An authored title has nothing to render from, so the parameters
            // stay null rather than an empty array pretending to be a set.
            'title_params' => $titleKey === null ? null : $titleParams,
            'impact' => $severity->toImpact(),
            'severity' => $severity,
            'signal_source' => $source,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => $aiOwned,
            'trigger_metric_key' => $triggerMetricKey,
            'started_at' => $check?->checked_at ?? now(),
        ]);

        // 2. Attach the primary monitor to the affected-component pivot so the
        //    incident serializes its affected set (name + component status) to
        //    the client. Without this the pivot is empty and the Flutter view
        //    reads affectedCount=0 with a blank monitor name. The component
        //    status freezes the monitor's current health at open time and
        //    mirrors it as the live status.
        //
        //    The two columns are named for `ComponentStatus` and carry
        //    `MonitorStatus` values, which reads like a bug and is not: the
        //    client decodes them with `statusKeyFromWire()`, whose vocabulary is
        //    `up`/`down`/`degraded`/`paused` and whose fallback for anything
        //    else is a blue `info` badge. Writing a real `ComponentStatus` here
        //    would turn every affected monitor grey-blue on the incident page,
        //    silently. An `IncidentSeverity::toComponentStatus()` existed for
        //    exactly that and was never called by anything; it is gone rather
        //    than left as an invitation.
        $componentStatus = $monitor->last_status?->value ?? MonitorStatus::Down->value;
        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => $componentStatus,
            'component_status_current' => $componentStatus,
        ]);

        return $incident;
    }
}
