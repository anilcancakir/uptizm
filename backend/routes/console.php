<?php

use App\Jobs\AggregateMonitorDailyUptime;
use App\Jobs\AlarmContentArchiveFailures;
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
// THE ONE SCHEDULED TASK WATCHED BY SENTRY, and the choice is forced rather
// than preferred: this org's plan includes a single cron monitor seat, while
// this file registers eleven tasks.
//
// It is not the most important one. `monitoring:schedule-checks` above is, and
// that is exactly why it is not this: if check dispatch stops, every dashboard
// empties and every status page freezes, so the product reports its own outage
// within minutes. This job is the opposite shape. If it stops, checks keep
// running and nothing looks wrong; the uptime strip simply stops advancing, and
// the gap is only visible to someone who happens to compare a status page
// against the raw check table. A silent failure that corrupts published data is
// what a cron monitor is for.
//
// Its cadence also fits the tool. Cron monitoring expresses a schedule in
// minutes at best, so the thirty-second dispatcher cannot be represented
// honestly, and watching it would spend roughly 170k check-ins a month against
// a plan whose overage budget is zero.
Schedule::job(new AggregateMonitorDailyUptime)
    ->hourly()
    ->onOneServer()
    ->name('monitoring:daily-uptime')
    ->sentryMonitor();

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

// Watch the archive's failure RATE, because every individual failure is already
// quiet by design: it logs, releases its claim, and reads downstream exactly like
// content that had not changed. That is why a degradation from 6% to 39% of
// writes over five days went unnoticed in August 2026.
//
// Hourly, on the `feeds` lane rather than `content`: a serial worker parked in the
// rclone mount is precisely the state worth reporting, and an alarm queued behind
// that stall would never fire. Minute 23 keeps it clear of the nightly jobs above.
Schedule::job(new AlarmContentArchiveFailures)
    ->hourlyAt(23)
    ->withoutOverlapping()
    ->onOneServer()
    ->name('monitoring:alarm-content-archive-failures');

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
    // Built by the job rather than interpolated here: the value comes from env, it
    // lands in a raw cron field, and this file loads on every artisan invocation, so
    // a bad value takes down every command. {@see RefreshProxySources::cronExpression()}
    // carries both hazards and why an interval of an hour or more moves to the hour
    // field instead of being clamped into the minute field.
    ->cron(RefreshProxySources::cronExpression())
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
//
// Two limits worth stating here rather than leaving a reader to infer that a
// silent alarm means a healthy fleet. `probe_region_health` is written in
// exactly one place, `LocalProbeEngine::recordRegionHealth()`, and that engine
// probes the SYSTEM team's catalog monitors only, so a region reached through
// the relay never gets a row and can never be alarmed on. And on a deployment
// with no proxy provider wired, the catalog leaves through
// `ProxyRegions::directRegion()`, where there is no exit to blame and therefore
// no refusal at all, so `last_failure_at` stays null and the streak never
// starts. Both are true of production today: zero proxy rows, one region with a
// row, and that region on the direct path.
//
// Neither is a defect in this job. It watches a proxy pool, and it will start
// answering the moment there is one.
Schedule::job(new AlarmDarkProbeRegions)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('proxy:alarm-dark-regions');

// Drop the failed jobs nobody is going to retry.
//
// Laravel ships `queue:prune-failed` and nothing scheduled it, so the table only
// ever grew: 121 rows on production, none from the last 24 hours, the oldest
// from a defect fixed weeks ago. A failed job is worth reading while it is fresh
// and is archaeology after that, and an unbounded table is the kind of thing
// that is noticed as a disk-space alert rather than as a queue problem.
//
// Two weeks, which is longer than the seven days an AI suggestion gets because
// this is the record of something that BROKE: a fortnight covers a holiday plus
// the week either side of it. Daily at 04:50, in the same quiet band as the two
// prunes above and after both, so the nightly housekeeping stays serialised.
Schedule::command('queue:prune-failed', ['--hours=336'])
    ->dailyAt('04:50')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('queue:prune-failed');

// Re-read each billing rail and correct any team entitlement that drifted.
//
// `Schedule::command` rather than the `Schedule::job` every task above uses, and
// the difference is not a style drift: this one IS a command, because an
// operator diagnosing a single customer runs `billing:reconcile --team=<id>` by
// hand, and a job carries no CLI surface to run that way. `queue:prune-failed`
// directly above is registered the same way for the same reason.
//
// Hourly. Both rails abandon a delivery: RevenueCat after five retries inside
// about three hours, Stripe after roughly three days, and after that the drift
// is permanent and silent (a dropped EXPIRATION is a paid tier held for free,
// a dropped INITIAL_PURCHASE is a paying customer stuck on free with no
// self-serve way out). Hourly heals inside the window where the damage arrives
// rather than after it, and it is comfortably inside Stripe's. The trade it
// accepts is one authoritative RevenueCat read per store team per run, so the
// cadence stops being free once the store fleet is large; the answer then is a
// per-run cap or a staggered sweep, not a slower schedule, because a slower
// schedule is measured in customers on the wrong tier.
//
// `withoutOverlapping` matters here rather than being boilerplate: the sweep
// makes one network read per store team, so it can outlive its own tick, and two
// of them would re-read the same subscriber and write the same row twice.
Schedule::command('billing:reconcile')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('billing:reconcile');
