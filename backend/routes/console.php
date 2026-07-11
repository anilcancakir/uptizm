<?php

use App\Jobs\AggregateMonitorDailyUptime;
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
Schedule::job(new AggregateMonitorDailyUptime)
    ->dailyAt('00:15')
    ->onOneServer()
    ->name('monitoring:daily-uptime');

// Fan out SSL certificate checks once a day (supervisor `ssl` queue,
// single-server, unique lock prevents overlap with a still-running fan-out).
Schedule::job(new ScheduleSslChecks)
    ->dailyAt('03:00')
    ->onOneServer()
    ->name('monitoring:schedule-ssl-checks');

// Sweep the ai_mode=suggest fleet for response-time anomalies every 2 minutes
// (supervisor `ai` queue, single-server, unique lock prevents overlap with a
// still-running sweep so a still-open episode is not re-enqueued mid-fan-out).
Schedule::job(new SweepAiSuggestions)
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('monitoring:sweep-ai-suggestions');
