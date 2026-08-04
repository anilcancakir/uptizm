<?php

namespace App\Jobs;

use App\Models\ProbeRegionHealth;
use App\Services\Monitoring\LocalProbeEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Raises an operator-facing alarm when a proxy region has produced NO
 * reading, platform-wide, for several consecutive intervals.
 *
 * There is no platform-level notification path in this codebase, and
 * `config('uptizm.staff_emails')` is the Filament panel's access allowlist,
 * not a delivery channel; turning it into one would invent a notification
 * path this product has not chosen. So a structured `Log::error()` IS the
 * whole alarm here, matching the house pattern for an operator-facing
 * platform failure ({@see ArchiveContent::failed()}, `PerformMonitorCheck.php:330`).
 *
 * "Exactly once per crossing" is the load-bearing property: a region that has
 * been dark for an hour must not re-log on every five-minute tick this job
 * runs, or the alarm becomes noise an operator learns to ignore. The guard is
 * `probe_region_health.alarmed_at`, set here and cleared ONLY by
 * {@see LocalProbeEngine::recordRegionHealth()} the moment a probe in that
 * region succeeds again, so a region that recovers and later goes dark a
 * second time alarms a second time.
 */
class AlarmDarkProbeRegions implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt per tick. A retry would re-read the same rows before the
     * next scheduled tick does so anyway, and a failed alarm write is not
     * worth re-fighting mid-tick for a job whose whole job is to log.
     *
     * @var int
     */
    public $tries = 1;

    public function __construct()
    {
        $this->onQueue('feeds');
    }

    /**
     * Log every region whose consecutive empty-interval streak has crossed
     * the configured threshold and has not already been alarmed for THIS
     * streak.
     */
    public function handle(): void
    {
        $threshold = (int) config('proxy.health.failure_threshold');

        // 1. Count the interval FIRST, here, once per tick. The engine cannot do it:
        //    it runs per (monitor, region), so eight catalog monitors going dark in
        //    one tick would advance a per-interval counter eight times and cross any
        //    threshold immediately. This job is the only thing that runs once per
        //    interval, so a region whose last attempt failed and which has not
        //    succeeded since gets exactly one increment per tick. A region that
        //    succeeded in the meantime was already reset to zero by the engine.
        ProbeRegionHealth::query()
            ->whereNotNull('last_failure_at')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('last_success_at')
                    // `>=`, not `>`: these columns are whole-second `timestampTz`, so a
                    // failure and an earlier success inside the same second compare
                    // EQUAL and a strict comparison would read a dark region as
                    // healthy and never increment. The tie is resolved toward dark on
                    // purpose: the engine already zeroes the streak the moment a probe
                    // succeeds, so an extra interval counted here costs one tick of
                    // patience, while a missed one costs the alarm entirely.
                    ->orWhereColumn('last_failure_at', '>=', 'last_success_at');
            })
            ->increment('consecutive_empty_intervals');

        // 2. Then alarm on whatever crossed, once per crossing.
        ProbeRegionHealth::query()
            ->where('consecutive_empty_intervals', '>=', $threshold)
            ->whereNull('alarmed_at')
            ->get()
            ->each(function (ProbeRegionHealth $health) use ($threshold): void {
                Log::error('Proxy region has produced no reading for several consecutive intervals.', [
                    'region' => $health->region,
                    'consecutive_empty_intervals' => $health->consecutive_empty_intervals,
                    'threshold' => $threshold,
                    'healthy_proxy_count' => $health->healthy_proxy_count,
                    'last_success_at' => $health->last_success_at?->toIso8601String(),
                    'last_failure_at' => $health->last_failure_at?->toIso8601String(),
                ]);

                $health->update(['alarmed_at' => now()]);
            });
    }
}
