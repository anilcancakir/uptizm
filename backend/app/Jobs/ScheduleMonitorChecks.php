<?php

namespace App\Jobs;

use App\Models\Monitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Periodic fan-out job (scheduled every 30s) that picks up monitors whose
 * `next_check_at` has elapsed and queues one {@see PerformMonitorCheck} per
 * configured region.
 *
 * `next_check_at` is advanced inside a transaction, before any dispatch, so
 * a worker crash mid-tick cannot double-dispatch the same monitor.
 */
class ScheduleMonitorChecks implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Seconds for which only one copy of this job may run, guarding against
     * an overlapping 30s tick while a prior run is still fanning out.
     *
     * @var int
     */
    public $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('scheduling');
    }

    public function handle(): void
    {
        // 1. Load every monitor whose clock has elapsed as of this tick, with the
        //    owning team attached: PerformMonitorCheck reads
        //    `$monitor->team->is_system` to pick the probe transport, and
        //    SerializesModels carries a LOADED relation through the queue, so
        //    batching it here is what keeps a busy tick from paying one extra
        //    SELECT per due monitor.
        $due = Monitor::query()->due()->with('team')->get();

        foreach ($due as $monitor) {
            $this->dispatchForMonitor($monitor);
        }
    }

    /**
     * Advance a single monitor's `next_check_at` clock and fan out one
     * {@see PerformMonitorCheck} per configured region.
     */
    protected function dispatchForMonitor(Monitor $monitor): void
    {
        DB::transaction(function () use ($monitor): void {
            // 1. Advance the clock first so a crash between here and the
            //    dispatch loop below cannot cause the same tick to fire twice.
            //    The cadence is the EFFECTIVE one, so a plan's floor binds here,
            //    where checks are actually spent, and not only on the write path
            //    that validated the column.
            $monitor->forceFill([
                'next_check_at' => now()->addSeconds($monitor->effectiveCheckIntervalSec()),
            ])->save();

            // 2. Fan out one job per region configured on the monitor.
            foreach ($monitor->regions as $region) {
                PerformMonitorCheck::dispatch($monitor, $region)->onQueue('checks');
            }
        });
    }
}
