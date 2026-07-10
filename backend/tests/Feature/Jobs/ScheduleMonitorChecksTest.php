<?php

namespace Tests\Feature\Jobs;

use App\Enums\MonitorType;
use App\Jobs\PerformMonitorCheck;
use App\Jobs\ScheduleMonitorChecks;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the 30s scheduler tick: a due monitor must fan out one
 * {@see PerformMonitorCheck} per configured region, advance its
 * `next_check_at` clock BEFORE dispatch (so a crash cannot double-dispatch),
 * and a monitor that is no longer due must not be re-dispatched on the
 * next immediate tick.
 */
class ScheduleMonitorChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fans_out_one_job_per_region_and_advances_the_clock(): void
    {
        $monitor = $this->makeMonitor(regions: ['us-east', 'eu-west']);

        Bus::fake([
            PerformMonitorCheck::class,
        ]);

        (new ScheduleMonitorChecks)->handle();

        // 1. One job per configured region, targeting this monitor.
        Bus::assertDispatchedTimes(PerformMonitorCheck::class, 2);
        Bus::assertDispatched(PerformMonitorCheck::class, function (PerformMonitorCheck $job) use ($monitor): bool {
            return $job->monitor->id === $monitor->id && $job->region === 'us-east';
        });
        Bus::assertDispatched(PerformMonitorCheck::class, function (PerformMonitorCheck $job) use ($monitor): bool {
            return $job->monitor->id === $monitor->id && $job->region === 'eu-west';
        });

        // 2. The clock advanced by exactly the configured interval.
        $fresh = $monitor->fresh();
        $this->assertEqualsWithDelta(
            now()->addSeconds($monitor->check_interval_sec)->getTimestamp(),
            $fresh->next_check_at->getTimestamp(),
            1,
        );
    }

    public function test_a_second_immediate_run_does_not_redispatch_a_no_longer_due_monitor(): void
    {
        $this->makeMonitor(regions: ['us-east', 'eu-west']);

        Bus::fake([
            PerformMonitorCheck::class,
        ]);

        (new ScheduleMonitorChecks)->handle();
        Bus::assertDispatchedTimes(PerformMonitorCheck::class, 2);

        // A second immediate tick finds the monitor no longer due.
        (new ScheduleMonitorChecks)->handle();
        Bus::assertDispatchedTimes(PerformMonitorCheck::class, 2);
    }

    protected function makeMonitor(array $regions): Monitor
    {
        $user = User::query()->create([
            'name' => 'Scheduler Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Scheduler Team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 30,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
            'regions' => $regions,
            'status' => 'active',
            'next_check_at' => now()->subMinute(),
        ]);
    }
}
