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

        // 2. The clock advanced by exactly the EFFECTIVE interval. The fixture
        //    stores 30s on a Free team whose floor is 180s, so this is 180: the
        //    two cases below pin that clamp on its own.
        $fresh = $monitor->fresh();
        $this->assertEqualsWithDelta(
            now()->addSeconds($monitor->effectiveCheckIntervalSec())->getTimestamp(),
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

    /**
     * The plan's floor binds where checks are SPENT, not only where the column
     * is written.
     *
     * A downgraded team keeps a faster stored interval (the write gate is on the
     * delta, so the monitor stays editable), and before this the loop honoured
     * that stored value forever: measured 31s, 59s, 32s, 58s and 59s gaps on a
     * 30s monitor whose Free plan allows 180s. The paid cadence survived the
     * downgrade indefinitely.
     */
    public function test_it_arms_the_next_check_at_the_plans_floor_not_the_stored_interval(): void
    {
        $monitor = $this->makeMonitor(regions: ['us-east']);
        // Free is the default plan for a team made here, and its floor is 180s.
        $monitor->forceFill(['check_interval_sec' => 30])->save();

        Bus::fake([
            PerformMonitorCheck::class,
        ]);

        $before = now();
        (new ScheduleMonitorChecks)->handle();

        $next = $monitor->fresh()->next_check_at;
        $this->assertNotNull($next);
        $this->assertEqualsWithDelta(
            $before->addSeconds(180)->getTimestamp(),
            $next->getTimestamp(),
            1,
            'the clock advanced by the stored 30s instead of the plan floor',
        );
    }

    /**
     * A monitor already slower than the floor is left alone: the clamp is a
     * floor, not a cadence override.
     */
    public function test_it_keeps_an_interval_already_slower_than_the_floor(): void
    {
        $monitor = $this->makeMonitor(regions: ['us-east']);
        $monitor->forceFill(['check_interval_sec' => 600])->save();

        Bus::fake([
            PerformMonitorCheck::class,
        ]);

        $before = now();
        (new ScheduleMonitorChecks)->handle();

        $this->assertEqualsWithDelta(
            $before->addSeconds(600)->getTimestamp(),
            $monitor->fresh()->next_check_at->getTimestamp(),
            1,
        );
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
