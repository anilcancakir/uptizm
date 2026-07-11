<?php

namespace App\Jobs;

use App\Enums\AiMode;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Models\AiSuggestion;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetric;
use App\Services\Ai\ResponseTimeAnomalyDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled anomaly sweep: the fan-out that turns raw response-time history into
 * triage work for every monitor in AI suggest mode.
 *
 * Every ~2 minutes it scans the ai_mode=suggest fleet, runs the pure
 * {@see ResponseTimeAnomalyDetector} over each monitor's recent response_ms
 * window, and hands any candidate to {@see TriageAnomalyCandidate} on the `ai`
 * queue. Two guards keep the inbox calm across the tight re-scan cadence:
 *
 *  - Scope: only `suggest` monitors are swept. `auto` is deferred and `off`
 *    monitors are never touched.
 *  - Dispatch-time dedupe: a candidate whose `dedupe_key` already has a live
 *    suggestion is dropped here, so re-scanning a still-open episode every two
 *    minutes does not re-enqueue triage for it.
 */
class SweepAiSuggestions implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Most recent checks pulled into the detector window per monitor. Bounds the
     * per-monitor scan cost and keeps the statistical baseline recent.
     *
     * @var int
     */
    private const WINDOW_SIZE = 120;

    /**
     * Seconds for which only one copy of this sweep may run. Sized just under
     * the two-minute tick so an overlapping tick cannot double-enqueue while a
     * prior sweep is still fanning out, yet the lock frees before the next
     * legitimate tick.
     *
     * @var int
     */
    public $uniqueFor = 115;

    public function __construct()
    {
        $this->onQueue('ai');
    }

    /**
     * Sweep every suggest-mode monitor and fan out one triage per fresh anomaly.
     *
     * @param  ResponseTimeAnomalyDetector  $detector  The pure statistical detector.
     */
    public function handle(ResponseTimeAnomalyDetector $detector): void
    {
        // Chunk the fleet so a large workspace never loads every monitor into
        // memory at once. Auto and off monitors are excluded by the scope.
        Monitor::query()
            ->where('ai_mode', AiMode::Suggest)
            ->each(function (Monitor $monitor) use ($detector): void {
                $this->sweepMonitor($monitor, $detector);
            });
    }

    /**
     * Score one monitor's recent window and, on a fresh candidate, fan out a
     * triage job.
     */
    private function sweepMonitor(Monitor $monitor, ResponseTimeAnomalyDetector $detector): void
    {
        // 1. Pull the bounded, oldest-to-newest response_ms window.
        $window = $this->loadWindow($monitor);
        if ($window->isEmpty()) {
            return;
        }

        // 2. Score it. A null result means no actionable anomaly (or cold-start
        //    with no configured bounds), so there is nothing to dispatch.
        $candidate = $detector->detect(
            $window->pluck('response_ms')->map(static fn (mixed $ms): int => (int) $ms)->all(),
            $this->buildConfig($monitor, $window),
        );
        if ($candidate === null) {
            return;
        }

        // 3. Dispatch-time dedupe: never re-enqueue an episode that already has a
        //    live suggestion. This blunts the two-minute re-scan re-dispatch.
        if (AiSuggestion::query()->where('dedupe_key', $candidate->dedupeKey)->exists()) {
            return;
        }

        // 4. Hand the non-secret candidate DTO to the triage job on the ai queue.
        TriageAnomalyCandidate::dispatch((string) $monitor->id, $candidate->toArray())->onQueue('ai');
    }

    /**
     * Load the monitor's most recent response_ms checks, oldest-to-newest, as
     * the detector expects.
     *
     * @return Collection<int, MonitorCheck>
     */
    private function loadWindow(Monitor $monitor): Collection
    {
        return MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->whereNotNull('response_ms')
            ->orderByDesc('checked_at')
            ->limit(self::WINDOW_SIZE)
            ->get([
                'response_ms',
                'checked_at',
            ])
            ->reverse()
            ->values();
    }

    /**
     * Build the detector config from the monitor: its region, any configured
     * static bounds, and the window span so the cold-start window-age gate can
     * be evaluated.
     *
     * @param  Collection<int, MonitorCheck>  $window
     * @return array<string, mixed>
     */
    private function buildConfig(Monitor $monitor, Collection $window): array
    {
        $bounds = $this->resolveBounds($monitor);

        return [
            'monitor_id' => (string) $monitor->id,
            'region' => $this->resolveRegion($monitor),
            'warn_bound' => $bounds['warn'],
            'critical_bound' => $bounds['critical'],
            'window_from' => $window->first()->checked_at,
            'window_to' => $window->last()->checked_at,
        ];
    }

    /**
     * Resolve static response-time thresholds from the monitor's own millisecond
     * metric, when one is configured. With none, both bounds are null and the
     * detector's cold-start branch returns null rather than flagging.
     *
     * @return array{warn: ?float, critical: ?float}
     */
    private function resolveBounds(Monitor $monitor): array
    {
        $metric = MonitorMetric::query()
            ->where('monitor_id', $monitor->id)
            ->where('type', MetricType::Numeric->value)
            ->where('unit', MetricUnit::Millisecond->value)
            ->first();

        return [
            'warn' => $metric?->warn_bound,
            'critical' => $metric?->critical_bound,
        ];
    }

    /**
     * Pick the monitor's first configured region, defaulting to `global` when it
     * probes from none in particular.
     */
    private function resolveRegion(Monitor $monitor): string
    {
        $regions = is_array($monitor->regions) ? $monitor->regions : [];

        return $regions === [] ? 'global' : (string) reset($regions);
    }
}
