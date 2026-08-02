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
 * Rollup for the public status page's uptime strip. Iterates every monitor and
 * aggregates a day's checks into a single `monitor_daily_uptime` row via
 * {@see ComponentDailyUptimeService}.
 *
 * TODAY AS WELL AS YESTERDAY, and that is the point. This used to aggregate only
 * yesterday, on one nightly run, so today's row simply did not exist until 00:15
 * the following morning. The strip's last cell is today, so every status page
 * showed its most recent day as unmeasured while checks were actively running,
 * and a day that went down would not have coloured until the outage was already
 * history. The gap was invisible while a missing day was silently gap-filled as
 * operational; making that gap honest is what surfaced it.
 *
 * Yesterday is re-aggregated on every run rather than only after midnight. The
 * numbers are stable so the write is a no-op, and it buys something real: a run
 * missed at 00:15 (a scheduler outage, a deploy) used to leave that day missing
 * forever, and now the next run repairs it.
 *
 * Scheduled hourly in `routes/console.php`. Safe to re-dispatch: the underlying
 * upsert is keyed on (monitor_id, date).
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
        $days = [now()->subDay(), now()];

        Monitor::query()->chunkById(100, function ($monitors) use ($svc, $days): void {
            foreach ($monitors as $monitor) {
                foreach ($days as $day) {
                    $svc->aggregateDay($monitor, $day);
                }
            }
        });
    }
}
