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
        // 1. Load every monitor whose clock has elapsed as of this tick.
        $due = Monitor::query()->due()->get();

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
            $monitor->forceFill([
                'next_check_at' => now()->addSeconds($monitor->check_interval_sec),
            ])->save();

            // 2. Fan out one job per region configured on the monitor.
            foreach ($monitor->regions as $region) {
                PerformMonitorCheck::dispatch($monitor, $region)->onQueue('checks');
            }
        });
    }
}
