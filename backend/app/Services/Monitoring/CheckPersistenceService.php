<?php

namespace App\Services\Monitoring;

use App\Enums\MetricType;
use App\Enums\MonitorStatus;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetricValue;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\StatusPages\StatusPageCache;
use App\Support\Monitoring\CheckResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Durable persistence seam for a {@see CheckResult} landing from the edge
 * relay. It records the check row, refreshes the monitor's denormalized
 * last-state columns, freezes any configured metric samples, and hands off
 * to {@see ThresholdEvaluator} so incident signaling runs against the
 * freshly committed state.
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
        protected StatusPageCache $statusPageCache,
    ) {}

    /**
     * Persist a check result idempotently and dispatch threshold evaluation.
     *
     * A replay of an already-seen `probe_run_id` returns without touching the
     * database or the evaluator.
     *
     * @param  Monitor  $monitor  The monitor the probe ran against.
     * @param  CheckResult  $result  The probe outcome from the relay worker.
     */
    public function persist(Monitor $monitor, CheckResult $result): void
    {
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
            ];
            $this->withMonitorLock($monitor, function () use ($monitor, $result, &$outcome): void {
                // 3a. Write the check, refresh the denorm columns, and freeze
                //     metric samples in a single transaction so a partial batch
                //     never lands.
                [$check, $samples] = $this->persistWithinTransaction($monitor, $result);

                // 3b. Threshold/signal routing runs only after the check is
                //     durable so a failure here cannot corrupt committed
                //     telemetry. The monitor is refreshed so the evaluator reads
                //     the persisted consecutive-fail counter, not a stale value.
                //     The evaluator returns the opened/resolved refs but must
                //     NOT dispatch: it runs under this monitor lock.
                $outcome = $this->evaluator->evaluate(
                    monitor: $monitor->refresh(),
                    check: $check,
                    metricSamples: $samples,
                );
            });

            // 4. Dispatch incident notifications OFF-LOCK: the monitor lock is
            //    already released by the time the closure returns, so a queued
            //    send never happens while the per-monitor critical section is
            //    held.
            $this->dispatchIncidentNotifications($monitor, $outcome);
        } finally {
            $payloadLock->release();
        }
    }

    /**
     * Dispatch the incident lifecycle notifications for a completed evaluation,
     * each gated on the monitor's matching alert flag.
     *
     * Both notifications are `ShouldQueue`, so a send enqueues rather than
     * blocks; the alert flag is the gate, so a monitor opted out of down or
     * recovery alerts stays silent. Recipients are the incident's owning team
     * users. Called only AFTER {@see self::withMonitorLock()} returns so no
     * send ever runs inside the per-monitor lock.
     *
     * @param  array{opened: ?Incident, resolved: ?Incident}  $outcome
     */
    protected function dispatchIncidentNotifications(Monitor $monitor, array $outcome): void
    {
        // 1. A threshold open pages the team, gated on the down-alert flag.
        if ($outcome['opened'] !== null && $monitor->alert_on_down) {
            Notification::send($outcome['opened']->team->users, new IncidentOpened($outcome['opened']));
        }

        // 2. A recovery clears the page, gated on the recover-alert flag.
        if ($outcome['resolved'] !== null && $monitor->alert_on_recover) {
            Notification::send($outcome['resolved']->team->users, new IncidentResolved($outcome['resolved']));
        }

        // 3. Bust the public status-page cache off-lock whenever the lifecycle
        //    changed. An open/resolve mutates the affected monitor's component
        //    status, so every containing page's cached read model is now stale
        //    and must be forgotten immediately, not after the 60s TTL. This is
        //    wired at the pivot boundary (not an Incident observer), which fires
        //    before monitors()->attach() and so cannot see the containing pages.
        if ($outcome['opened'] !== null || $outcome['resolved'] !== null) {
            $this->statusPageCache->invalidateForMonitors([$monitor->id]);
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
     * Insert the check row, update the monitor denorm columns, and persist
     * extracted metric samples atomically.
     *
     * @return array{0: MonitorCheck, 1: array<string, float>} The persisted
     *                                                         check and the
     *                                                         numeric samples
     *                                                         keyed by metric
     *                                                         key.
     */
    protected function persistWithinTransaction(Monitor $monitor, CheckResult $result): array
    {
        return DB::transaction(function () use ($monitor, $result): array {
            // 1. Persist the raw check row first so telemetry is durable
            //    before any follow-up write in the same transaction.
            $check = MonitorCheck::query()->create([
                'id' => (string) Str::orderedUuid(),
                'monitor_id' => $monitor->id,
                'team_id' => $monitor->team_id,
                'region' => $result->region,
                'checked_at' => $result->checkedAt,
                'status' => $result->status,
                'status_code' => $result->statusCode,
                'response_ms' => $result->responseMs,
                'response_headers' => $result->responseHeaders,
                'response_body_preview' => $result->responseBodyPreview,
                'error_message' => $result->errorMessage,
                'timing_dns_ms' => $result->timingDnsMs,
                'timing_connect_ms' => $result->timingConnectMs,
                'timing_tls_ms' => $result->timingTlsMs,
                'timing_ttfb_ms' => $result->timingTtfbMs,
                'timing_download_ms' => $result->timingDownloadMs,
                'probe_run_id' => $result->probeRunId,
            ]);

            // 2. Refresh the denormalized last-state columns and the failure
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
                ]);

            // 3. Freeze configured metric samples against this check; the
            //    numeric samples flow out to the threshold evaluator.
            $samples = $this->extractAndPersistMetrics($monitor, $check, $result);

            return [
                $check,
                $samples,
            ];
        });
    }

    /**
     * Run each configured metric through {@see MetricExtractor}, persist
     * successful extractions with their band frozen at insert time, and
     * return the numeric samples keyed by `metric_key` for the evaluator.
     *
     * @return array<string, float>
     */
    protected function extractAndPersistMetrics(Monitor $monitor, MonitorCheck $check, CheckResult $result): array
    {
        $metrics = $monitor->metrics()->get();
        if ($metrics->isEmpty()) {
            return [];
        }

        $body = $result->responseBodyPreview ?? '';
        $headers = $this->normalizeHeaders($result->responseHeaders);
        $now = now();
        $samples = [];
        $rows = [];

        foreach ($metrics as $metric) {
            $extracted = $this->extractor->extract(
                source: $metric->source,
                extractionPath: $metric->extraction_path ?? '',
                type: $metric->type,
                body: $body,
                headers: $headers,
                statusCode: $result->statusCode,
            );

            // 1. Skip rules that failed to extract or produced a type mismatch;
            //    a failed extraction records no sample.
            if ($extracted->error !== null || $extracted->value === null || ! $extracted->typeValid) {
                continue;
            }

            // 2. Split the stringy value into the typed column that matches the
            //    metric shape, banding numerics at insert so later threshold
            //    edits never rewrite history.
            $numeric = null;
            $statusValue = null;
            $stringValue = null;
            $band = null;

            if ($metric->type === MetricType::Numeric) {
                $numeric = (float) $extracted->value;
                $samples[$metric->key] = $numeric;
                $band = $metric->threshold_direction !== null
                    ? ThresholdEvaluator::band(
                        direction: $metric->threshold_direction,
                        value: $numeric,
                        warnBound: $metric->warn_bound !== null ? (float) $metric->warn_bound : null,
                        criticalBound: $metric->critical_bound !== null ? (float) $metric->critical_bound : null,
                    )
                    : null;
            } elseif ($metric->type === MetricType::Status) {
                $statusValue = $extracted->value;
            } else {
                $stringValue = $extracted->value;
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
