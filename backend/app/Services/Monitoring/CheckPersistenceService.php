<?php

namespace App\Services\Monitoring;

use App\Enums\MetricType;
use App\Enums\MonitorStatus;
use App\Jobs\PerformMonitorCheck;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetric;
use App\Models\MonitorMetricValue;
use App\Support\Monitoring\CheckResult;
use Illuminate\Database\Eloquent\Collection;
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
 * Metric samples arrive PRE-EXTRACTED from {@see PerformMonitorCheck},
 * the only stage that holds the full response body: `response_body_preview` is
 * capped at 10 KiB and the body never crosses the queue hop, so extracting here
 * would silently cap every metric at that offset. This service still owns the
 * band freezing and the insert; it no longer owns the reading. Extraction from
 * the preview remains the fallback for a payload that reached it with nothing
 * pre-extracted (a TCP probe, a filtered content type, an older worker, or a
 * direct caller such as the manual check path).
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
        protected MetricExtractor $extractor,
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
     *                                          key, already extracted from the
     *                                          full body one stage upstream.
     *                                          Defaulted so the manual and test
     *                                          call sites keep their two-argument
     *                                          shape.
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
        ?array $samples = null,
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
        //    notification.
        if ($result->probeRefused) {
            $this->recordProbeRefusal($monitor, $result);

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
                [$check, $numericSamples, $statusChange] = $this->persistWithinTransaction(
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
                    metricSamples: $numericSamples,
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
     *     1: array<string, float>,
     *     2: array{from: ?MonitorStatus, to: MonitorStatus}|null
     * } The persisted check, the numeric samples keyed by metric key, and the
     *   status transition when the monitor flipped health (null otherwise).
     */
    protected function persistWithinTransaction(
        Monitor $monitor,
        CheckResult $result,
        ?array $samples,
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
            //    counter with a single atomic UPDATE. A `down` result advances
            //    the streak with a DB-side `consecutive_fails + 1` so two
            //    concurrent regions cannot lose an increment to a stale
            //    read-modify-write; any other outcome resets the streak to zero.
            $isDown = $result->status === MonitorStatus::Down;
            Monitor::query()
                ->whereKey($monitor->id)
                ->update([
                    'last_status' => $result->status->value,
                    'last_checked_at' => $result->checkedAt,
                    'last_response_ms' => $result->responseMs,
                    'consecutive_fails' => $isDown
                        ? DB::raw('consecutive_fails + 1')
                        : 0,
                    // A probe that reached the target clears any earlier edge
                    // refusal, in the same atomic UPDATE that refreshes the
                    // status. Left behind, the warning would outlive the
                    // misconfiguration that caused it.
                    'last_probe_error' => null,
                    'last_probe_error_at' => null,
                ]);

            // 4. Freeze configured metric samples against this check; the
            //    numeric samples flow out to the threshold evaluator.
            $numericSamples = $this->persistMetricSamples($monitor, $check, $result, $samples);

            return [
                $check,
                $numericSamples,
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
     * band frozen at insert time, and return the numeric ones keyed by
     * `metric_key` for the evaluator.
     *
     * The values normally arrive already read out of the full response body and
     * already validated against their declared type, leaving this method only the
     * choice of typed column. When nothing arrives it falls back to reading the
     * truncated preview itself, which is all this stage holds.
     *
     * @param  array<string, string>  $samples  Raw values keyed by metric key.
     * @return array<string, float>
     */
    protected function persistMetricSamples(
        Monitor $monitor,
        MonitorCheck $check,
        CheckResult $result,
        ?array $samples,
    ): array {
        $metrics = $monitor->metrics()->get();
        if ($metrics->isEmpty()) {
            return [];
        }

        // 1. Nothing pre-extracted means the body never reached the extracting
        //    stage, so the truncated preview is all there is to read. This is
        //    the pre-split behaviour, kept for TCP probes, content types the
        //    edge filtered out, worker deployments older than the body field,
        //    and direct callers such as the manual check path.
        //    NULL, not empty: an empty array means extraction DID run against the
        //    full body and legitimately matched nothing, and falling back there
        //    would re-read the 10 KiB truncated preview that moving extraction
        //    upstream exists to stop using.
        if ($samples === null) {
            $samples = $this->extractFromPreview($metrics, $result);
        }

        $now = now();
        $numericSamples = [];
        $rows = [];

        foreach ($metrics as $metric) {
            // 2. A metric with no sample extracted nothing, here or upstream;
            //    a failed extraction records no row.
            $value = $samples[$metric->key] ?? null;
            if ($value === null) {
                continue;
            }

            // 3. Split the stringy value into the typed column that matches the
            //    metric shape, banding numerics at insert so later threshold
            //    edits never rewrite history.
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
                $stringValue = $value;
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

        // 4. Bulk insert so a monitor with many metrics costs one round-trip.
        if ($rows !== []) {
            MonitorMetricValue::query()->insert($rows);
        }

        return $numericSamples;
    }

    /**
     * Fallback extraction against the 10 KiB `response_body_preview`, in the
     * same shape the upstream full-body extraction produces.
     *
     * @param  Collection<int, MonitorMetric>  $metrics
     * @return array<string, string>
     */
    protected function extractFromPreview(Collection $metrics, CheckResult $result): array
    {
        $body = $result->responseBodyPreview ?? '';
        $headers = $this->normalizeHeaders($result->responseHeaders);
        $samples = [];

        foreach ($metrics as $metric) {
            $extracted = $this->extractor->extract(
                source: $metric->source,
                extractionPath: $metric->extraction_path ?? '',
                type: $metric->type,
                body: $body,
                headers: $headers,
                statusCode: $result->statusCode,
            );

            // A failed extraction or a type mismatch contributes no sample.
            if ($extracted->error !== null || $extracted->value === null || ! $extracted->typeValid) {
                continue;
            }

            $samples[$metric->key] = $extracted->value;
        }

        return $samples;
    }

    /**
     * Lower-case header keys so {@see MetricExtractor}'s header lookup matches
     * regardless of the casing the worker returned.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            $out[strtolower((string) $key)] = (string) $value;
        }

        return $out;
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
}
