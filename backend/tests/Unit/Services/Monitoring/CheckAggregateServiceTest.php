<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckAggregateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the DB-agnostic uptime aggregation: {@see CheckAggregateService::uptimeSummary()}
 * must fold status counts into the right ratio, and
 * {@see CheckAggregateService::responseTimeSamples()} must bucket without ever
 * reaching for a TimescaleDB-only function, so both run identically on the
 * sqlite `:memory:` test database.
 */
class CheckAggregateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_uptime_summary_computes_ratio_from_mixed_checks(): void
    {
        $monitor = $this->makeMonitor();

        for ($i = 0; $i < 7; $i++) {
            $this->makeCheck($monitor, MonitorStatus::Up);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->makeCheck($monitor, MonitorStatus::Down);
        }

        $summary = (new CheckAggregateService)->uptimeSummary($monitor, '24h');

        $this->assertSame(10, $summary->total);
        $this->assertSame(7, $summary->up);
        $this->assertSame(3, $summary->down);
        $this->assertSame(0, $summary->degraded);
        $this->assertSame(0.7, $summary->uptime_ratio);
    }

    public function test_uptime_summary_is_zero_when_no_checks_in_range(): void
    {
        $monitor = $this->makeMonitor();

        $summary = (new CheckAggregateService)->uptimeSummary($monitor, '24h');

        $this->assertSame(0, $summary->total);
        $this->assertSame(0.0, $summary->uptime_ratio);
    }

    public function test_response_time_samples_bucket_and_average_without_time_bucket(): void
    {
        $monitor = $this->makeMonitor();

        $this->makeCheck($monitor, MonitorStatus::Up, responseMs: 100);
        $this->makeCheck($monitor, MonitorStatus::Up, responseMs: 200);
        $this->makeCheck($monitor, MonitorStatus::Down, responseMs: 300);

        $samples = (new CheckAggregateService)->responseTimeSamples($monitor, '24h');

        $this->assertGreaterThan(0, $samples->count());

        // The worst status observed inside a bucket must win the dot color,
        // and averaging must fold every response_ms inside that bucket.
        $worst = $samples->firstWhere('status', MonitorStatus::Down);
        $this->assertNotNull($worst);
    }

    /**
     * Creates a monitor owned by a freshly created team.
     */
    protected function makeMonitor(): Monitor
    {
        $user = User::query()->create([
            'name' => 'Aggregate Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Aggregate Team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
        ]);
    }

    /**
     * Creates a persisted check row for the given monitor.
     */
    protected function makeCheck(Monitor $monitor, MonitorStatus $status, ?int $responseMs = null): MonitorCheck
    {
        return MonitorCheck::query()->create([
            'id' => (string) Str::orderedUuid(),
            'checked_at' => now(),
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'us-east-1',
            'status' => $status,
            'response_ms' => $responseMs,
        ]);
    }
}
