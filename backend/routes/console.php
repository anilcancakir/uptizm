<?php

use App\Jobs\AggregateMonitorDailyUptime;
use App\Jobs\AlarmDarkProbeRegions;
use App\Jobs\BustStatusPageCacheForMaintenanceBoundaries;
use App\Jobs\DispatchWeeklyDigests;
use App\Jobs\IngestServiceFeeds;
use App\Jobs\PruneContentArchive;
use App\Jobs\PruneExpiredAiSuggestions;
use App\Jobs\RefreshProxySources;
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

// Delete the AI suggestions that expired without anyone acting on them
// (supervisor `ai` queue, single-server, unique lock prevents overlap with a
// still-running sweep).
//
// The sweep above creates a suggestion per fresh anomaly every two minutes and
// stamps it to expire in seven days, but nothing ever deleted one: `expires_at`
// was read only as a visibility filter in the inbox query, so an unactioned
// suggestion left the UI and stayed in the table forever.
//
// Daily at 04:40, in the quiet band after the 03:00 SSL fan-out and the 04:10
// archive prune and before the 06:00 Monday digest, so the housekeeping jobs never
// share a tick.
Schedule::job(new PruneExpiredAiSuggestions)
    ->dailyAt('04:40')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('ai:prune-expired-suggestions');

// Fan out one weekly digest per team entitled to read one (supervisor `ai`
// queue, single-server, unique lock prevents overlap with a still-enqueuing
// fan-out).
//
// This trigger was missing entirely: GenerateWeeklyDigest was written, tested and
// plan-gated, but nothing outside the test suite ever dispatched it, so
// `GET /incidents/digest` answered 404 forever on a Business plan.
//
// Monday 06:00 UTC, and the day follows from the job's own window rather than
// taste: it digests the seven days ending at TODAY's UTC start-of-day, so a
// Monday run covers the previous Monday through Sunday, a whole calendar week
// with no partial day at either end. The hour is after the 03:00 SSL fan-out and
// the 04:10 archive prune, so the three heavy jobs do not contend.
Schedule::job(new DispatchWeeklyDigests)
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('ai:dispatch-weekly-digests');

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

// Refresh every region's proxy pool from its configured source (supervisor `feeds`
// queue, single-server, unique lock prevents overlap with a still-running refresh).
//
// The cadence reads config('proxy.refresh_minutes') (default 60) through a raw cron
// expression rather than a fixed ->hourly(): Schedule's fluent helpers have no
// "every N configurable minutes" method, and a hardcoded literal would silently
// drift out of sync with a changed UPTIZM_PROXY_REFRESH_MINUTES.
Schedule::job(new RefreshProxySources)
    // CLAMPED to 1..59 rather than trusted. This value is interpolated straight into a
    // cron field, and `routes/console.php` loads on EVERY artisan invocation, so
    // `UPTIZM_PROXY_REFRESH_MINUTES=0` (or any non-numeric value, which `(int)` makes 0)
    // throws `Invalid CRON field value */0` and takes down `schedule:list`, `migrate`,
    // `config:clear`, everything, including the commands you would reach for to fix it.
    // Measured: exit 1 on `schedule:list` with the value unclamped.
    ->cron('*/'.max(1, min(59, (int) config('proxy.refresh_minutes'))).' * * * *')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('proxy:refresh-sources');

// Alarm when a proxy region has produced no reading for several consecutive
// intervals (supervisor `feeds` queue, single-server, unique lock prevents a
// still-running check from overlapping the next tick).
//
// Every five minutes: frequent enough that an operator learns of a dark
// region within minutes of `probe_region_health.consecutive_empty_intervals`
// crossing `config('proxy.health.failure_threshold')`, without re-querying on
// every 30-second monitor-check tick.
Schedule::job(new AlarmDarkProbeRegions)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('proxy:alarm-dark-regions');
