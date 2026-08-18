<?php

namespace App\Services\Monitoring;

use App\Enums\IncidentStatus;
use App\Enums\MetricType;
use App\Enums\MonitorStatus;
use App\Jobs\PerformMonitorCheck;
use App\Jobs\ScheduleMonitorChecks;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetricValue;
use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\ReadingFreshness;
use Carbon\Carbon;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Durable persistence seam for a {@see CheckResult} landing from the edge
 * relay. It records the check row, refreshes the monitor's denormalized
 * last-state columns, freezes any configured metric samples, and hands off
 * to {@see ThresholdEvaluator} so incident signaling runs against the
 * freshly committed state.
 *
 * Metric samples arrive PRE-EXTRACTED from {@see PerformMonitorCheck}, the only
 * stage that holds the full response body and the only stage that extracts at
 * all: `response_body_preview` is capped at 10 KiB and the body never crosses
 * the queue hop, so extracting here would silently cap every metric at that
 * offset. This service owns the band freezing and the insert; it does NOT read
 * bodies. It has no fallback extraction either, and that absence is the fix for
 * a real defect: the fallback was gated on a null the producer never sent, so a
 * response whose content type the edge filtered recorded no metric values at
 * all, while the fallback that was supposed to cover exactly that case could not
 * fire. The producer now resolves the preview itself, which leaves ONE
 * extraction site and one body-resolution rule.
 *
 * Whatever `$samples` carries is therefore the whole of what the check measured,
 * and an empty set means nothing was extractable rather than nothing was tried.
 *
 * Idempotency is the load-bearing invariant: the relay may deliver the same
 * probe payload more than once (job retries, at-least-once callbacks), so a
 * replay of the SAME `probe_run_id` must be a total no-op: one check row, one
 * metric-value set, `consecutive_fails` incremented once, and no second
 * incident. The guard is app-layer ({@see self::alreadyPersisted()}) rather
 * than a bare reliance on the DB unique index, because the later TimescaleDB
 * hypertable promotion cannot keep a unique index over only
 * `(monitor_id, region, probe_run_id)` (the partition column must participate).
 *
 * Side-effect ordering is deliberate: metric extraction and the denorm update
 * share the check row's transaction so a partial batch never lands, while
 * threshold evaluation runs AFTER the commit so a signaling failure cannot
 * corrupt the persisted telemetry.
 */
class CheckPersistenceService
{
    /**
     * Time-to-live (seconds) of the per-payload persistence lock. Short
     * enough to self-heal after a crashed worker, long enough to cover a
     * single persist transaction plus threshold evaluation.
     */
    protected const int LOCK_TTL_SECONDS = 10;

    /**
     * Seconds a monitor-level acquirer waits for a concurrent region's hold
     * to clear before giving up. Bounded so a stuck holder cannot wedge a
     * worker indefinitely, generous enough to outlast one persist cycle.
     */
    protected const int MONITOR_LOCK_WAIT_SECONDS = 10;

    public function __construct(
        protected ThresholdEvaluator $evaluator,
        protected IncidentDispatcher $incidentDispatcher,
    ) {}

    /**
     * Persist a check result idempotently and dispatch threshold evaluation.
     *
     * A replay of an already-seen `probe_run_id` returns without touching the
     * database or the evaluator.
     *
     * @param  Monitor  $monitor  The monitor the probe ran against.
     * @param  CheckResult  $result  The probe outcome from the relay worker.
     * @param  array<string, string>  $samples  Raw metric values keyed by metric
     *                                          key, already extracted one stage
     *                                          upstream. Everything this check
     *                                          measured; nothing is re-read here.
     *                                          Defaulted so a caller with no
     *                                          metrics to report keeps the
     *                                          two-argument shape.
     * @param  string|null  $contentHash  ADDRESS of the archived content version
     *                                    this check resolved to, decided one stage
     *                                    upstream. NOT necessarily the hash of
     *                                    this check's own bytes: on an unchanged
     *                                    page it is an earlier body's raw hash,
     *                                    because that is the body the stored blob
     *                                    holds. Defaulted for the same reason as
     *                                    `$samples`.
     * @param  string|null  $contentHashNormalized  This check's own change signal.
     */
    public function persist(
        Monitor $monitor,
        CheckResult $result,
        array $samples = [],
        ?string $contentHash = null,
        ?string $contentHashNormalized = null,
    ): void {
        // 0. A probe the EDGE refused measured nothing about the target, so it
        //    never becomes a check. Recording it as `down` would advance
        //    `consecutive_fails`, cross `incident_threshold`, open an incident and
        //    page a responder for a service that is up. Recording it as `up` would
        //    be worse: it would reset the streak and mask a real outage
        //    underneath. The only honest answer is no verdict at all, plus a
        //    monitor-level error the operator can act on.
        //
        //    Nothing below runs: no lock, no dedup, no threshold evaluation, no
        //    notification. The one thing a refusal DOES settle is an incident
        //    that can no longer be evidenced, which is the other half of the same
        //    honesty (see `closeIncidentGoneDark()`).
        if ($result->probeRefused) {
            $this->recordProbeRefusal($monitor, $result);
            $this->closeIncidentGoneDark($monitor, $result);

            return;
        }

        // 1. Serialize concurrent retries of the SAME payload. A holder that
        //    cannot acquire the lock is a live duplicate of this exact
        //    delivery: the current holder will persist, so this attempt bows
        //    out. This lock is keyed per (monitor, region, probe_run_id), so
        //    it does NOT serialize two distinct regions of the same monitor.
        $payloadLock = Cache::lock($this->payloadLockKey($monitor, $result), self::LOCK_TTL_SECONDS);
        if (! $payloadLock->get()) {
            return;
        }

        try {
            // 2. Durable dedup guard: a check already recorded for this
            //    (monitor, region, probe_run_id) means the payload was fully
            //    processed on an earlier delivery. Return before any write or
            //    evaluation so nothing double-counts.
            if ($this->alreadyPersisted($monitor, $result)) {
                return;
            }

            // 3. Serialize the denorm-counter update and threshold evaluation
            //    per MONITOR, not per payload. Two regions of the same monitor
            //    carry distinct probe_run_ids and clear the payload lock in
            //    parallel; without this second lock their read-modify-write on
            //    consecutive_fails would race and the incident-existence guard
            //    could double-open. Both regions must still be processed, so we
            //    BLOCK-wait for the hold rather than bow out.
            $outcome = [
                'opened' => null,
                'resolved' => null,
                'status_change' => null,
            ];
            $this->withMonitorLock($monitor, function () use (
                $monitor,
                $result,
                $samples,
                $contentHash,
                $contentHashNormalized,
                &$outcome,
            ): void {
                // 3a. Write the check, refresh the denorm columns, and freeze
                //     metric samples in a single transaction so a partial batch
                //     never lands. The status transition is captured INSIDE the
                //     transaction (fresh in-lock DB read) so a concurrent region
                //     of the same monitor cannot double-detect the same flip.
                [$check, $recorded, $statusChange] = $this->persistWithinTransaction(
                    $monitor,
                    $result,
                    $samples,
                    $contentHash,
                    $contentHashNormalized,
                );

                // 3b. Threshold/signal routing runs only after the check is
                //     durable so a failure here cannot corrupt committed
                //     telemetry. The monitor is refreshed so the evaluator reads
                //     the persisted consecutive-fail counter, not a stale value.
                //     The evaluator returns the opened/resolved refs but must
                //     NOT dispatch: it runs under this monitor lock.
                $outcome = $this->evaluator->evaluate(
                    monitor: $monitor->refresh(),
                    check: $check,
                    metricSamples: $recorded['numeric'],
                    stringSamples: $recorded['string'],
                );

                // 3c. Thread the in-lock status transition onto the evaluator's
                //     opened/resolved outcome; the assignment above replaces the
                //     whole array, so the transition is merged in afterwards.
                $outcome['status_change'] = $statusChange;
            });

            // 4. Dispatch incident notifications OFF-LOCK: the monitor lock is
            //    already released by the time the closure returns, so a queued
            //    send never happens while the per-monitor critical section is
            //    held. The dispatcher is shared with the manual write path so
            //    both routes fire the same side effects in the same order.
            $this->incidentDispatcher->dispatch($monitor, $outcome);
        } finally {
            $payloadLock->release();
        }
    }

    /**
     * Run `$critical` while holding the monitor-level lock, blocking until the
     * lock is free (bounded by {@see self::MONITOR_LOCK_WAIT_SECONDS}) so a
     * concurrent region of the same monitor runs the denorm-update + evaluate
     * strictly one at a time. Releases the lock even when `$critical` throws.
     */
    protected function withMonitorLock(Monitor $monitor, callable $critical): void
    {
        $monitorLock = Cache::lock($this->monitorLockKey($monitor), self::LOCK_TTL_SECONDS);
        $monitorLock->block(self::MONITOR_LOCK_WAIT_SECONDS);

        try {
            $critical();
        } finally {
            $monitorLock->release();
        }
    }

    /**
     * True when a check row already exists for this monitor, region, and
     * probe run. This is the durable idempotency guard: it survives job
     * retries and the hypertable promotion where a partial-column unique
     * index cannot.
     */
    protected function alreadyPersisted(Monitor $monitor, CheckResult $result): bool
    {
        return MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->where('region', $result->region)
            ->where('probe_run_id', $result->probeRunId)
            ->exists();
    }

    /**
     * Insert the check row, update the monitor denorm columns, persist extracted
     * metric samples, and detect the health transition atomically.
     *
     * @param  array<string, string>  $samples  Pre-extracted raw metric values.
     * @param  string|null  $contentHash  Archived-version address resolved upstream.
     * @param  string|null  $contentHashNormalized  This check's change signal.
     * @return array{
     *     0: MonitorCheck,
     *     1: array{numeric: array<string, float>, string: array<string, string>},
     *     2: array{from: ?MonitorStatus, to: MonitorStatus}|null
     * } The persisted check, the recorded samples split by channel, and the
     *   status transition when the monitor flipped health (null otherwise).
     */
    protected function persistWithinTransaction(
        Monitor $monitor,
        CheckResult $result,
        array $samples,
        ?string $contentHash = null,
        ?string $contentHashNormalized = null,
    ): array {
        return DB::transaction(function () use (
            $monitor,
            $result,
            $samples,
            $contentHash,
            $contentHashNormalized,
        ): array {
            // 1. Read the committed prior status INSIDE the lock/transaction,
            //    BEFORE the denorm UPDATE overwrites it. A concurrent region of
            //    the same monitor serializes through the monitor lock, so this
            //    read observes the previous region's committed status and the
            //    same flip is never detected twice. The fresh-model read applies
            //    the MonitorStatus cast, yielding the enum (or null on a brand
            //    new monitor whose first status is being recorded now).
            $priorStatus = Monitor::query()
                ->whereKey($monitor->id)
                ->first(['last_status'])?->last_status;

            // 2. Persist the raw check row first so telemetry is durable
            //    before any follow-up write in the same transaction.
            $check = MonitorCheck::query()->create([
                'id' => (string) Str::orderedUuid(),
                'monitor_id' => $monitor->id,
                'team_id' => $monitor->team_id,
                'region' => $result->region,
                // Where the probe ACTUALLY ran. `region` above is the region the
                // caller asked for, which is not the same claim.
                'colo' => $result->colo,
                // The proxy exit a locally-produced check egressed through; null
                // on a worker-produced check, which has `colo` instead. See
                // CheckResult::$exitVia for why this is a separate column.
                'exit_via' => $result->exitVia,
                'checked_at' => $result->checkedAt,
                'status' => $result->status,
                'status_code' => $result->statusCode,
                'response_ms' => $result->responseMs,
                'response_headers' => $result->responseHeaders,
                'response_body_preview' => $result->responseBodyPreview,
                // The ADDRESS of the archived version this content resolved to,
                // decided by the stage that held the body. On an unchanged page
                // it is an EARLIER body's raw hash, not a hash of these bytes.
                'content_hash' => $contentHash,
                'content_hash_normalized' => $contentHashNormalized,
                'error_message' => $result->errorMessage,
                'timing_dns_ms' => $result->timingDnsMs,
                'timing_connect_ms' => $result->timingConnectMs,
                'timing_tls_ms' => $result->timingTlsMs,
                'timing_ttfb_ms' => $result->timingTtfbMs,
                'timing_download_ms' => $result->timingDownloadMs,
                'probe_run_id' => $result->probeRunId,
                // Both columns come from ONE nullable source, so they can never
                // disagree. NULL on both means the monitor asserted nothing, which
                // is why `assertions_passed` had to lose its `DEFAULT TRUE`: a
                // monitor with no rules was recording "every assertion passed", and
                // a status page would then cite a result nobody measured.
                //
                // `passed` is read, never re-derived. All-rules-must-pass and
                // skipped-is-not-a-failure live in the edge's evaluator; deciding
                // them again here would be a second implementation in another
                // language, and the two would drift.
                'assertions_passed' => $result->assertions === null
                    ? null
                    : $result->assertions['passed'],
                'assertion_results' => $result->assertions === null
                    ? null
                    : $result->assertions['results'],
            ]);

            // 3. Refresh the denormalized last-state columns and the failure
            //    counter with a single atomic UPDATE. Any non-down outcome resets
            //    the streak to zero, which is the published rule: one healthy
            //    region clears it, so a single bad region cannot page anybody.
            //
            //    A down outcome recomputes the streak in TICKS rather than adding
            //    one per region result. The old `consecutive_fails + 1` counted
            //    region-results, so a monitor probing from three regions gathered
            //    three increments in a single tick and crossed the default
            //    threshold of 2 on the FIRST one. `incident_threshold` is named
            //    and documented as "not the first failure, the N in a row", and
            //    the landing page sells it as absorbing a transient flake; with
            //    more than one region it absorbed nothing, and a ten-second blip
            //    that hit the whole target paged immediately.
            //
            //    Recomputed under the per-monitor lock this method already holds,
            //    so the read-then-write cannot race the other regions of the same
            //    tick (the reason the old code needed a DB-side increment).
            $isDown = $result->status === MonitorStatus::Down;
            Monitor::query()
                ->whereKey($monitor->id)
                ->update([
                    'last_status' => $result->status->value,
                    'last_checked_at' => $result->checkedAt,
                    'last_response_ms' => $result->responseMs,
                    'consecutive_fails' => $isDown
                        ? $this->downStreak($monitor)
                        : 0,
                    // A probe that reached the target clears any earlier edge
                    // refusal, in the same atomic UPDATE that refreshes the
                    // status. Left behind, the warning would outlive the
                    // misconfiguration that caused it.
                    'last_probe_error' => null,
                    'last_probe_error_at' => null,
                ]);

            // 4. Freeze configured metric samples against this check; both
            //    channels flow out to the threshold evaluator.
            $recorded = $this->persistMetricSamples($monitor, $check, $result, $samples);

            return [
                $check,
                $recorded,
                $this->detectStatusChange($priorStatus, $result->status),
            ];
        });
    }

    /**
     * Decide whether a health flip should reach the live dashboard.
     *
     * Two guards:
     *  1. A `paused` result is a config action, not a check outcome, and never
     *     broadcasts.
     *  2. An unchanged status is not a transition.
     *
     * A NULL prior is a transition and does broadcast. It is the first-ever
     * status of a brand-new monitor, which is exactly the moment an operator is
     * watching: they save the monitor, `store()` sets `next_check_at` to now and
     * queues the probes, and the row sits on Pending until something tells the
     * screen otherwise. This used to be suppressed "to avoid a storm on initial
     * seeding", deferring to reconcile-on-nav, which only helps an operator who
     * navigates; standing on the list after a create, nothing ever arrived.
     *
     * The storm concern is already answered on the client and answered better:
     * `RealtimeService._markDirty` re-arms a 400ms debounce and drains a SET, so
     * a burst (a seeder writing many monitors, or several regions landing
     * together) collapses into one reload per target. Suppression loses the
     * event; debouncing merely batches it. Only ONE region can produce the null
     * prior anyway: the prior status is read inside the monitor lock before the
     * denorm UPDATE, so the regions that follow observe the committed status and
     * fall through guard 2.
     *
     * `MonitorStatusChanged` was always built for this: its `$from` is typed
     * `?MonitorStatus` and `broadcastWith()` sends `previous_status` through
     * `?->value`. The event and this detector simply disagreed.
     *
     * @return array{from: ?MonitorStatus, to: MonitorStatus}|null The transition
     *                                                             or null when no
     *                                                             broadcast is due.
     */
    protected function detectStatusChange(?MonitorStatus $prior, MonitorStatus $current): ?array
    {
        if ($current === MonitorStatus::Paused) {
            return null;
        }

        if ($prior === $current) {
            return null;
        }

        return [
            'from' => $prior,
            'to' => $current,
        ];
    }

    /**
     * Persist the pre-extracted metric samples against this check with their
     * band frozen at insert time, and return them split by channel for the
     * evaluator.
     *
     * The values arrive already read out of the response and already validated
     * against their declared type, leaving this method only the choice of typed
     * column and the band. Nothing is re-read: a sample absent from `$samples`
     * did not extract, and it records no row.
     *
     * The two returned channels stay separate all the way to
     * {@see ThresholdEvaluator::evaluate()} because they carry different types.
     * Numbers band through {@see ThresholdEvaluator::band()} against bounds;
     * text bands through {@see ThresholdEvaluator::bandString()} against value
     * lists. Both freeze here rather than being recomputed on read, so editing a
     * bound or a value list never rewrites what a historical check reported.
     *
     * @param  array<string, string>  $samples  Raw values keyed by metric key.
     * @return array{numeric: array<string, float>, string: array<string, string>}
     */
    protected function persistMetricSamples(
        Monitor $monitor,
        MonitorCheck $check,
        CheckResult $result,
        array $samples,
    ): array {
        $now = now();
        $numericSamples = [];
        $stringSamples = [];
        $rows = [];

        foreach ($monitor->metrics()->get() as $metric) {
            // 1. A metric with no sample extracted nothing upstream; a failed
            //    extraction records no row.
            $value = $samples[$metric->key] ?? null;
            if ($value === null) {
                continue;
            }

            // 2. Split the stringy value into the typed column that matches the
            //    metric shape, banding at insert so later threshold edits never
            //    rewrite history.
            $numeric = null;
            $statusValue = null;
            $stringValue = null;
            $band = null;

            if ($metric->type === MetricType::Numeric) {
                // The declared type is read here while the value was validated
                // against it one stage earlier, so a metric edited between the
                // two can pair a numeric column with a word. `(float)` would
                // record a silent zero that the evaluator bands and can page on.
                if (! is_numeric($value)) {
                    continue;
                }

                $numeric = (float) $value;
                $numericSamples[$metric->key] = $numeric;
                $band = $metric->threshold_direction !== null
                    ? ThresholdEvaluator::band(
                        direction: $metric->threshold_direction,
                        value: $numeric,
                        warnBound: $metric->warn_bound !== null ? (float) $metric->warn_bound : null,
                        criticalBound: $metric->critical_bound !== null ? (float) $metric->critical_bound : null,
                    )
                    : null;
            } elseif ($metric->type === MetricType::Status) {
                $statusValue = $value;
            } else {
                // The RAW value is stored and the RAW value is banded;
                // normalization lives inside the bander so both sides of the
                // comparison get it and neither the row nor the incident title
                // shows the operator something the target never served.
                //
                // Banding is gated on `MonitorMetric::alertsOnString()`, the
                // same predicate the incident decision uses, so the frozen
                // history and the paging decision agree BY CONSTRUCTION.
                // Through the API they would agree anyway, because that
                // predicate is false only when all three lists are empty and
                // the write path refuses a non-null `unmatched_band` in that
                // state. But that argument rests on one FormRequest rule, and a
                // seeder, a console write or a future importer does not go
                // through it; an inert metric with a stray `unmatched_band`
                // would otherwise freeze `critical` on every sample while
                // paging nobody, which reads as a silently ignored outage.
                $stringValue = $value;
                $stringSamples[$metric->key] = $value;
                $band = $metric->alertsOnString()
                    ? ThresholdEvaluator::bandString(
                        value: $value,
                        okValues: $metric->ok_values,
                        warnValues: $metric->warn_values,
                        criticalValues: $metric->critical_values,
                        unmatchedBand: $metric->unmatched_band,
                    )
                    : null;
            }

            $rows[] = [
                'id' => (string) Str::orderedUuid(),
                'recorded_at' => $result->checkedAt,
                'monitor_id' => $monitor->id,
                'team_id' => $monitor->team_id,
                'check_id' => $check->id,
                'metric_key' => $metric->key,
                'numeric_value' => $numeric,
                'string_value' => $stringValue,
                'status_value' => $statusValue,
                'band' => $band?->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // 3. Bulk insert so a monitor with many metrics costs one round-trip.
        if ($rows !== []) {
            MonitorMetricValue::query()->insert($rows);
        }

        return [
            'numeric' => $numericSamples,
            'string' => $stringSamples,
        ];
    }

    /**
     * Build the per-payload lock key from the same tuple the dedup guard
     * keys on, so the lock and the durable guard protect the exact same unit.
     */
    /**
     * Record a probe the edge refused, without letting it look like a check.
     *
     * Writes ONLY the two error columns. `last_status`, `last_checked_at` and
     * `consecutive_fails` are deliberately untouched: the monitor's health is
     * whatever the last real probe said, and a refusal is not evidence either
     * way. A mass UPDATE rather than a model save, so no observer treats this as
     * a health transition and nothing broadcasts.
     *
     * The message is truncated to the column width rather than allowed to throw;
     * losing the tail of an explanation is better than losing the fact that the
     * monitor is misconfigured.
     */
    protected function recordProbeRefusal(Monitor $monitor, CheckResult $result): void
    {
        Monitor::query()
            ->whereKey($monitor->id)
            ->update([
                'last_probe_error' => mb_substr(
                    $result->errorMessage ?? 'The edge refused this probe.',
                    0,
                    255,
                ),
                'last_probe_error_at' => $result->checkedAt,
            ]);
    }

    /**
     * Close an incident whose target has gone dark, because the evidence behind
     * it has stopped rather than because a recovery was seen.
     *
     * A refusal writes no check row, and the recovery path only ever runs on a
     * check. So an incident opened before the target became unmeasurable can
     * never close: measured on production 2026-08-18, openai.com and claude.ai
     * began serving a bot challenge and their incidents sat `detected` and
     * `critical` for FOUR DAYS while the probe correctly refused every tick.
     * Exactly the shape `SweepAiSuggestions` closed for the autonomous lane, in
     * another lane.
     *
     * "Dark" is the SAME threshold the public page uses to withhold a verdict,
     * shared from {@see ReadingFreshness} rather than restated here: a second
     * copy is how the two surfaces come to disagree about what stale means, and
     * that disagreement IS this defect.
     *
     * The asymmetry with the public page is what this fixes. That page has
     * withheld a verdict for a stale target all along; the incident table kept
     * asserting one. Nothing here touches `last_status`, which the page already
     * ignores in favour of the staleness rule.
     *
     * NO recovery dispatch, matching `IncidentWriteService`'s monitor-deleted
     * sweep, the other non-recovery close: `IncidentResolved` tells a responder
     * the service came back, and nothing came back. We stopped being able to
     * look.
     *
     * FAIL-CLOSED on everything it cannot judge. A reading still inside the
     * window keeps the incident (one refused tick is not a dark target, and a
     * real outage underneath must not be closed out from under a responder), and
     * a multi-component incident is never selected at all, because one component
     * going dark says nothing about the others on it.
     */
    protected function closeIncidentGoneDark(Monitor $monitor, CheckResult $result): void
    {
        // Measured against the moment the probe ran, not wall-clock now, so a
        // delayed or replayed delivery judges the window it actually saw.
        // Compared directly rather than through a signed `diffInSeconds`, which
        // returns a NEGATIVE value for a past timestamp and inverts the test.
        $cutoff = Carbon::instance($result->checkedAt)
            ->subSeconds(ReadingFreshness::STALE_AFTER_SECONDS);

        $newestReading = MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->value('checked_at');

        if ($newestReading !== null && $newestReading->gte($cutoff)) {
            return;
        }

        // The single-component constraint is a subquery, not a loop test. This
        // runs on EVERY refused tick of every dark monitor, so counting the pivot
        // per incident would be an N+1 on the hot refusal path.
        $incidents = Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->active()
            ->has('monitors', '=', 1)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        foreach ($incidents as $incident) {
            $this->resolveGoneDark($incident, $monitor);
        }
    }

    /**
     * Flip one incident to resolved under a row lock and say why on its timeline.
     *
     * Idempotent: two regions of the same monitor can refuse in the same tick,
     * and only the first may write the transition and the note.
     */
    protected function resolveGoneDark(Incident $incident, Monitor $monitor): void
    {
        $resolved = DB::transaction(function () use ($incident): ?Incident {
            $fresh = Incident::query()->lockForUpdate()->find($incident->getKey());

            if ($fresh === null || ! $fresh->lifecycle->isActive()) {
                return null;
            }

            $fresh->update([
                'lifecycle' => IncidentStatus::Resolved,
                'resolved_at' => now(),
            ]);

            return $fresh;
        });

        if ($resolved === null) {
            return;
        }

        // Internal, and NOT flagged autonomous: the client renders that flag as
        // an "Auto mode" badge, and no model took part in this.
        $resolved->updates()->create([
            'actor' => 'system',
            'author' => 'System',
            'status' => IncidentStatus::Resolved,
            'message' => "This incident was opened on a reading we can no longer take: {$monitor->name} is refusing "
                .'our probe, so no verdict is recorded at all. Closed because the evidence behind it stopped, not '
                .'because a recovery was observed.',
            'is_public' => false,
            'autonomous' => false,
            'display_at' => now(),
        ]);
    }

    protected function payloadLockKey(Monitor $monitor, CheckResult $result): string
    {
        return "check-persist:{$monitor->id}:{$result->region}:{$result->probeRunId}";
    }

    /**
     * Build the per-monitor lock key. Keyed on the monitor alone (no region or
     * probe run) so it serializes the denorm-counter update and threshold
     * evaluation across every region checking the same monitor.
     */
    protected function monitorLockKey(Monitor $monitor): string
    {
        return "check-persist-monitor:{$monitor->id}";
    }

    /**
     * How many consecutive TICKS this monitor has been down in every region it
     * probes from.
     *
     * A tick is one scheduling round: {@see ScheduleMonitorChecks}
     * fans out one job per configured region per interval. There is no tick id to
     * group by (`probe_run_id` is minted per dispatch in
     * {@see RelayClient}, so it identifies a single
     * probe, not a round), so the round is reconstructed positionally: take each
     * region's most recent checks newest-first and read them off in lockstep. The
     * i-th entry of every region belongs to the i-th most recent tick.
     *
     * A tick counts only when EVERY configured region reported down for it. Two
     * consequences, both deliberate:
     *
     * - One healthy region stops the count, which is the rule the landing page
     *   publishes and the reason no quorum is claimed.
     * - A region MISSING from a tick also stops it. A refused probe writes no
     *   check row (see {@see recordProbeRefusal}, which deliberately leaves the
     *   status and the streak alone), and our own edge refusing to probe is not
     *   evidence about the customer's endpoint either way.
     *
     * A monitor with no regions configured keeps the old per-result counting: it
     * cannot have ticks, and answering zero would silently stop it alerting.
     *
     * The scan is bounded at one more than the threshold, because nothing above
     * that changes any decision.
     */
    protected function downStreak(Monitor $monitor): int|Expression
    {
        /** @var list<string> $regions */
        $regions = $monitor->regions ?? [];

        // A monitor with no regions configured cannot have ticks, and answering
        // zero would silently stop it alerting. It keeps the DB-side increment
        // rather than reading the counter off this instance: the instance was
        // loaded before the lock was taken, so two results in a row would both
        // read the same value and the streak would stall at one. That race is
        // exactly what the raw expression was here for.
        if ($regions === []) {
            return DB::raw('consecutive_fails + 1');
        }

        return $this->consecutiveFullyDownTicks($monitor, $regions);
    }

    /**
     * How many consecutive TICKS this monitor has been down in every region.
     *
     * @param  list<string>  $regions  The monitor's configured regions.
     */
    protected function consecutiveFullyDownTicks(Monitor $monitor, array $regions): int
    {
        $depth = ($monitor->incident_threshold ?? Monitor::DEFAULT_INCIDENT_THRESHOLD) + 1;

        $byRegion = [];
        foreach ($regions as $region) {
            $byRegion[$region] = MonitorCheck::query()
                ->where('monitor_id', $monitor->id)
                ->where('region', $region)
                ->orderByDesc('checked_at')
                ->limit($depth)
                ->pluck('status')
                ->all();
        }

        $ticks = 0;
        for ($index = 0; $index < $depth; $index++) {
            foreach ($regions as $region) {
                $status = $byRegion[$region][$index] ?? null;

                if ($status === null) {
                    return $ticks;
                }

                $value = $status instanceof MonitorStatus ? $status : MonitorStatus::from((string) $status);

                if ($value !== MonitorStatus::Down) {
                    return $ticks;
                }
            }

            $ticks++;
        }

        return $ticks;
    }
}
