<?php

use App\Jobs\AggregateMonitorDailyUptime;
use App\Jobs\BustStatusPageCacheForMaintenanceBoundaries;
use App\Jobs\IngestServiceFeeds;
use App\Jobs\PruneContentArchive;
use App\Jobs\ScheduleMonitorChecks;
use App\Jobs\ScheduleSslChecks;
use App\Jobs\SweepAiSuggestions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fan out monitor checks every 30 seconds (supervisor `scheduling` queue,
// single-server, unique lock prevents overlap with a still-running tick).
Schedule::job(new ScheduleMonitorChecks)
    ->everyThirtySeconds()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('monitoring:schedule-checks');

// Roll yesterday's monitor_checks into the monitor_daily_uptime strip so
// the public status page can render its uptime bars without scanning the
// raw check table on every request.
// Hourly, not nightly. The strip's last cell is TODAY, so a once-a-day run left
// every status page showing its most recent day as unmeasured until the following
// morning. Hourly is ample: the strip's granularity is a whole day.
Schedule::job(new AggregateMonitorDailyUptime)
    ->hourly()
    ->onOneServer()
    ->name('monitoring:daily-uptime');

// Fan out SSL certificate checks once a day (supervisor `ssl` queue,
// single-server, unique lock prevents overlap with a still-running fan-out).
Schedule::job(new ScheduleSslChecks)
    ->dailyAt('03:00')
    ->onOneServer()
    ->name('monitoring:schedule-ssl-checks');

// Expire archived page content nobody has resolved to inside the retention
// window (supervisor `content` queue, so an unlink parked in the rclone FUSE
// mount can never occupy a slot the monitoring checks need). Runs after the
// 03:00 SSL fan-out so the two nightly jobs do not contend for the mount, and
// `withoutOverlapping` matters here rather than being boilerplate: a sweep can
// outlive its own tick on a slow mount, and two of them would race the
// blob-survivor query.
Schedule::job(new PruneContentArchive)
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('monitoring:prune-content-archive');

// Sweep the ai_mode=suggest fleet for response-time anomalies every 2 minutes
// (supervisor `ai` queue, single-server, unique lock prevents overlap with a
// still-running sweep so a still-open episode is not re-enqueued mid-fan-out).
Schedule::job(new SweepAiSuggestions)
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('monitoring:sweep-ai-suggestions');

// Bust the public status-page cache when a maintenance window opens or closes.
// Every other bust in this system hangs off a write; a window's boundaries are
// timestamps, so nothing happens in the application when the clock crosses them
// and the page keeps serving a read model built before the window existed.
// Every minute, because a boundary is minute-grained.
Schedule::job(new BustStatusPageCacheForMaintenanceBoundaries)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('status-pages:bust-maintenance-boundaries');

// Poll each published catalog service's official status feed (supervisor `feeds`
// queue, single-server, unique lock prevents overlap with a still-enqueuing
// fan-out).
//
// Two minutes, not one, and the number is derived rather than chosen:
// FeedFetcher::MIN_INTERVAL_SECONDS is a hard 60-second floor per service,
// enforced against the newest snapshot's `fetched_at`. A fetch lands a moment
// AFTER the tick that ordered it, so on a one-minute schedule every other tick
// would arrive fractionally inside the floor and refuse, making the real cadence
// two minutes while `schedule:list` advertised one. This states the true cadence
// instead.
Schedule::job(new IngestServiceFeeds)
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('services:ingest-feeds');
