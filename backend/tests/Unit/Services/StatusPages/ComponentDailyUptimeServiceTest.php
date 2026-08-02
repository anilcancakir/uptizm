<?php

namespace Tests\Unit\Services\StatusPages;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\StatusPages\ComponentDailyUptimeService;
use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the N+1 kill on the status page's uptime read: batching N monitors'
 * 90-day strips must issue exactly ONE `monitor_daily_uptime` query and still
 * map each id to its own gap-filled 90-entry strip.
 */
class ComponentDailyUptimeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_strips_run_a_single_rollup_query(): void
    {
        $team = $this->makeTeam();
        $monitors = [
            $this->makeMonitor($team),
            $this->makeMonitor($team),
            $this->makeMonitor($team),
        ];

        $today = CarbonImmutable::now('UTC')->startOfDay()->format('Y-m-d');
        foreach ($monitors as $monitor) {
            $this->seedUptime($monitor, $today, 'operational');
        }

        $ids = array_map(static fn (Monitor $monitor): string => (string) $monitor->id, $monitors);

        DB::flushQueryLog();
        DB::enableQueryLog();

        (new ComponentDailyUptimeService)->last90DaysForMonitors($ids);

        $rollupQueries = array_filter(
            DB::getQueryLog(),
            static fn (array $entry): bool => str_contains($entry['query'], 'monitor_daily_uptime'),
        );

        DB::disableQueryLog();

        $this->assertCount(1, $rollupQueries);
    }

    public function test_batch_maps_each_id_to_its_own_ninety_entry_strip(): void
    {
        $team = $this->makeTeam();
        $healthy = $this->makeMonitor($team);
        $flaky = $this->makeMonitor($team);

        $today = CarbonImmutable::now('UTC')->startOfDay()->format('Y-m-d');
        $this->seedUptime($flaky, $today, 'major_outage', 40.0);

        $strips = (new ComponentDailyUptimeService)->last90DaysForMonitors([
            (string) $healthy->id,
            (string) $flaky->id,
        ]);

        $this->assertCount(90, $strips[$healthy->id]);
        $this->assertCount(90, $strips[$flaky->id]);

        // A gap-filled day is NULL, not `operational`. It used to be the latter, which
        // is how a monitor whose first probe had not run published ninety green days
        // and "100.00%" to a customer's own users. The seeded flaky day still paints
        // its real outage colour on its own strip only.
        $this->assertNull($strips[$healthy->id][88]['worst_status']);
        $this->assertSame('major_outage', $strips[$flaky->id][89]['worst_status']);
    }

    public function test_batch_returns_empty_for_no_ids(): void
    {
        $this->assertSame([], (new ComponentDailyUptimeService)->last90DaysForMonitors([]));
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Uptime Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Uptime Team',
        ]);
    }

    /**
     * Creates a monitor owned by the given team.
     */
    protected function makeMonitor(Team $team): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Component '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
        ]);
    }

    /**
     * Inserts one daily-uptime rollup row for the monitor.
     */
    protected function seedUptime(Monitor $monitor, string $date, string $worst, float $percent = 100.0): void
    {
        $row = [
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'date' => $date,
            'uptime_percent' => $percent,
            'total_checks' => 100,
            'failed_checks' => 0,
            'worst_status' => $worst,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (MigrationHelper::usesUuids()) {
            $row['id'] = (string) Str::orderedUuid();
        }

        DB::table('monitor_daily_uptime')->insert($row);
    }
}
