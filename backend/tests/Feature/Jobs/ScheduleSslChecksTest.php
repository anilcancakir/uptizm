<?php

namespace Tests\Feature\Jobs;

use App\Enums\MonitorType;
use App\Jobs\PerformSslCheck;
use App\Jobs\ScheduleMonitorChecks;
use App\Jobs\ScheduleSslChecks;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the daily SSL fan-out: one {@see PerformSslCheck} per https monitor
 * opted in to certificate tracking, and nothing at all for a monitor the
 * customer paused.
 *
 * The paused case is the reason this file exists. Unlike
 * {@see ScheduleMonitorChecks}, which selects through
 * `Monitor::scopeDue()` and therefore inherits its `status = 'active'` gate,
 * this job selected on `ssl_tracking` and the URL scheme alone. A paused
 * monitor kept being probed daily, and {@see PerformSslCheck::openSslIncident()}
 * could open a real incident and bust the public status-page cache for an
 * endpoint the customer had explicitly stopped watching. Pausing is a promise
 * of silence, and this was a write path that broke it.
 */
class ScheduleSslChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_one_check_per_tracked_https_monitor(): void
    {
        $team = $this->makeTeam();
        $tracked = $this->makeMonitor($team);
        $this->makeMonitor($team, ['ssl_tracking' => false]);
        $this->makeMonitor($team, ['url' => 'http://example.com/health']);

        Bus::fake([
            PerformSslCheck::class,
        ]);

        (new ScheduleSslChecks)->handle();

        Bus::assertDispatchedTimes(PerformSslCheck::class, 1);
        Bus::assertDispatched(PerformSslCheck::class, function (PerformSslCheck $job) use ($tracked): bool {
            return $job->monitorId === $tracked->id;
        });
    }

    public function test_it_skips_a_paused_monitor(): void
    {
        $team = $this->makeTeam();
        $this->makeMonitor($team, [
            'status' => 'paused',
            'next_check_at' => null,
        ]);

        Bus::fake([
            PerformSslCheck::class,
        ]);

        (new ScheduleSslChecks)->handle();

        Bus::assertNotDispatched(PerformSslCheck::class);
    }

    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'SSL Scheduler',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'SSL Team',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeMonitor(Team $team, array $overrides = []): Monitor
    {
        return Monitor::query()->create(array_merge([
            'team_id' => $team->id,
            'name' => 'API Uptime '.Str::random(6),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'ssl_tracking' => true,
            'ssl_alert_threshold_days' => 14,
        ], $overrides));
    }
}
