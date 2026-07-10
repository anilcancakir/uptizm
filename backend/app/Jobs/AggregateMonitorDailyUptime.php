<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\StatusPages\ComponentDailyUptimeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Nightly rollup for the public status page's uptime strip. Iterates every
 * monitor and aggregates yesterday's checks into a single
 * `monitor_daily_uptime` row via {@see ComponentDailyUptimeService}.
 *
 * Runs at 00:15 daily (scheduled in `routes/console.php`). Safe to
 * re-dispatch: the underlying upsert is keyed on (monitor_id, date).
 */
class AggregateMonitorDailyUptime implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue('aggregates');
    }

    public function handle(ComponentDailyUptimeService $svc): void
    {
        $yesterday = now()->subDay();

        Monitor::query()->chunkById(100, function ($monitors) use ($svc, $yesterday): void {
            foreach ($monitors as $monitor) {
                $svc->aggregateDay($monitor, $yesterday);
            }
        });
    }
}
