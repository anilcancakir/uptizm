<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckAggregateService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the DB-agnostic uptime aggregation: {@see CheckAggregateService::uptimeSummary()}
 * must fold status counts into the right ratio, and
 * {@see CheckAggregateService::responseTimeSamples()} must bucket without ever
 * reaching for a TimescaleDB-only function, so both run identically on the
 * sqlite `:memory:` test database.
 *
 * {@see CheckAggregateService::reliabilitySummary()} carries the same constraint
 * plus the arithmetic the reliability card got wrong: downtime is DISTINCT
 * cadence buckets converted to minutes, never a row count and never a ratio
 * multiplied back out over the window.
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
        //
        // A sample is a plain ARRAY in the endpoint's wire shape now, not a
        // synthetic MonitorCheck: hydrating ~1,400 throwaway models for the
        // default 24h range cost more than the query. So the status is the enum's
        // wire value rather than the enum.
        $worst = $samples->firstWhere('status', MonitorStatus::Down->value);
        $this->assertNotNull($worst);
    }

    public function test_reliability_summary_reports_two_down_minutes_for_the_production_shape(): void
    {
        $now = $this->freezeClock();
        $monitor = $this->makeMonitor(createdAt: $now->subHours(15));

        // 767 checks over the last 767 minutes with two of them down in two
        // distinct minutes: the shape production monitor
        // a276e7c5-26d5-4b53-b522-f0ce3b52d226 carried while the reliability
        // card claimed 26.2 down minutes on 7d and 112.3 on 30d.
        $rows = [];
        for ($i = 0; $i < 767; $i++) {
            $rows[] = [
                'checked_at' => $now->subMinutes($i),
                'status' => in_array($i, [10, 11], true) ? MonitorStatus::Down : MonitorStatus::Up,
            ];
        }
        $this->makeChecks($monitor, $rows);

        $service = new CheckAggregateService;

        foreach ([
            '7d',
            '30d',
        ] as $range) {
            $this->assertSame(
                2.0,
                $service->reliabilitySummary($monitor, $range)->down_minutes,
                "down minutes for range {$range}",
            );
        }

        $sevenDay = $service->reliabilitySummary($monitor, '7d');
        $thirtyDay = $service->reliabilitySummary($monitor, '30d');

        // The denominator is the FULL nominal window on both ranges, while the
        // coverage the monitor could possibly have is its 15-hour lifetime.
        $this->assertSame(10080, $sevenDay->window_minutes);
        $this->assertSame(43200, $thirtyDay->window_minutes);
        $this->assertSame(900.0, $sevenDay->observed_minutes);
        $this->assertSame(900.0, $thirtyDay->observed_minutes);
        $this->assertSame(767.0, $sevenDay->measured_minutes);

        // 900 COMPLETED one-minute grid slots between creation and now (the slot
        // holding `now` is in progress and is not expected yet), 767 measured.
        $this->assertSame(133.0, $sevenDay->gap_minutes);
    }

    public function test_reliability_summary_folds_five_regions_in_one_bucket_into_one_down_minute(): void
    {
        $now = $this->freezeClock();
        $monitor = $this->makeMonitor(createdAt: $now->subHours(15));

        // All five regions report the same outage seconds apart. Counting rows
        // instead of distinct buckets reads this as five down minutes.
        foreach ([
            'us-east',
            'us-west',
            'eu-west',
            'eu-central',
            'ap',
        ] as $offset => $region) {
            $this->makeChecks($monitor, [
                [
                    'checked_at' => $now->subMinutes(30)->addSeconds($offset),
                    'status' => MonitorStatus::Down,
                    'region' => $region,
                ],
            ]);
        }

        $summary = (new CheckAggregateService)->reliabilitySummary($monitor, '7d');

        $this->assertSame(1.0, $summary->down_minutes);
        $this->assertSame(1.0, $summary->measured_minutes);
    }

    public function test_reliability_summary_counts_a_disagreeing_bucket_as_down(): void
    {
        $now = $this->freezeClock();
        $monitor = $this->makeMonitor(createdAt: $now->subHours(15));

        // One region down, four up, same bucket: the budget folds to worst-seen
        // because ThresholdEvaluator pages a human on one region's failure.
        $this->makeChecks($monitor, [
            [
                'checked_at' => $now->subMinutes(30),
                'status' => MonitorStatus::Down,
                'region' => 'us-east',
            ],
            [
                'checked_at' => $now->subMinutes(30)->addSeconds(1),
                'status' => MonitorStatus::Up,
                'region' => 'us-west',
            ],
            [
                'checked_at' => $now->subMinutes(30)->addSeconds(2),
                'status' => MonitorStatus::Up,
                'region' => 'eu-west',
            ],
            [
                'checked_at' => $now->subMinutes(30)->addSeconds(3),
                'status' => MonitorStatus::Up,
                'region' => 'eu-central',
            ],
            [
                'checked_at' => $now->subMinutes(30)->addSeconds(4),
                'status' => MonitorStatus::Up,
                'region' => 'ap',
            ],
        ]);

        $summary = (new CheckAggregateService)->reliabilitySummary($monitor, '7d');

        $this->assertSame(1.0, $summary->down_minutes);
    }

    public function test_reliability_summary_excludes_degraded_from_down_minutes(): void
    {
        $now = $this->freezeClock();
        $monitor = $this->makeMonitor(createdAt: $now->subHours(15));

        $this->makeChecks($monitor, [
            [
                'checked_at' => $now->subMinutes(20),
                'status' => MonitorStatus::Degraded,
            ],
            [
                'checked_at' => $now->subMinutes(10),
                'status' => MonitorStatus::Down,
            ],
        ]);

        $summary = (new CheckAggregateService)->reliabilitySummary($monitor, '7d');

        // Degraded is measured coverage but not spent availability budget.
        $this->assertSame(1.0, $summary->down_minutes);
        $this->assertSame(2.0, $summary->measured_minutes);
    }

    public function test_reliability_summary_converts_thirty_second_buckets_to_half_minutes(): void
    {
        $now = $this->freezeClock();
        $monitor = $this->makeMonitor(intervalSec: 30, createdAt: $now->subHours(15));

        $this->makeChecks($monitor, [
            [
                'checked_at' => $now->subMinutes(10),
                'status' => MonitorStatus::Down,
            ],
            [
                'checked_at' => $now->subMinutes(10)->addSeconds(30),
                'status' => MonitorStatus::Down,
            ],
        ]);

        $summary = (new CheckAggregateService)->reliabilitySummary($monitor, '7d');

        // Two buckets on a 30-second cadence are ONE minute of downtime; a raw
        // bucket count would report two.
        $this->assertSame(1.0, $summary->down_minutes);
    }

    public function test_reliability_summary_scales_a_five_minute_bucket_to_five_minutes(): void
    {
        $now = $this->freezeClock();
        $monitor = $this->makeMonitor(intervalSec: 300, createdAt: $now->subHours(15));

        $this->makeChecks($monitor, [
            [
                'checked_at' => $now->subMinutes(10),
                'status' => MonitorStatus::Down,
            ],
            [
                'checked_at' => $now->subMinutes(10)->addSeconds(30),
                'status' => MonitorStatus::Down,
                'region' => 'eu-west',
            ],
        ]);

        $summary = (new CheckAggregateService)->reliabilitySummary($monitor, '7d');

        $this->assertSame(5.0, $summary->down_minutes);
    }

    public function test_reliability_summary_reports_no_gap_when_every_expected_bucket_has_a_check(): void
    {
        $now = $this->freezeClock();
        $monitor = $this->makeMonitor(intervalSec: 300, createdAt: $now->subMinutes(15));

        foreach ([
            15,
            10,
            5,
            0,
        ] as $minutesAgo) {
            $this->makeChecks($monitor, [
                [
                    'checked_at' => $now->subMinutes($minutesAgo),
                    'status' => MonitorStatus::Up,
                ],
            ]);
        }

        $summary = (new CheckAggregateService)->reliabilitySummary($monitor, '7d');

        $this->assertSame(0.0, $summary->gap_minutes);
        $this->assertSame(15.0, $summary->observed_minutes);
    }

    public function test_reliability_summary_keeps_the_gap_grid_derived_across_a_bucket_boundary(): void
    {
        $now = $this->freezeClock();

        // Coverage starts 137 seconds INTO a five-minute grid slot, so elapsed
        // seconds (763) divided by the cadence yields two completed buckets
        // while the grid itself holds three. The MIDDLE check is deliberately
        // missing, so the one absent bucket is a real blind spot rather than the
        // in-progress slot: deriving expected buckets from elapsed seconds
        // undercounts, finds no gap, and the assertion below catches it.
        $monitor = $this->makeMonitor(intervalSec: 300, createdAt: $now->subMinutes(15)->addSeconds(137));

        foreach ([
            $now->subMinutes(15)->addSeconds(137),
            $now->subMinutes(5),
        ] as $checkedAt) {
            $this->makeChecks($monitor, [
                [
                    'checked_at' => $checkedAt,
                    'status' => MonitorStatus::Up,
                ],
            ]);
        }

        $summary = (new CheckAggregateService)->reliabilitySummary($monitor, '7d');

        $this->assertGreaterThanOrEqual(0.0, $summary->gap_minutes);
        // Three completed grid slots, two measured: exactly one missing bucket.
        $this->assertSame(5.0, $summary->gap_minutes);
        $this->assertSame(10.0, $summary->measured_minutes);
    }

    public function test_reliability_summary_separates_never_measured_from_measured_and_fine(): void
    {
        $now = $this->freezeClock();
        $monitor = $this->makeMonitor(createdAt: $now->subDays(40));

        $summary = (new CheckAggregateService)->reliabilitySummary($monitor, '30d');

        // A 30-day-old monitor that recorded nothing has a full window of
        // observed time and no downtime; only measured_minutes tells the two
        // apart. The grid expects both edge slots, so the raw gap is one slot
        // MORE than the coverage; it is capped at observed_minutes, because a
        // gap larger than its own window reads as a broken number.
        $this->assertSame(43200, $summary->window_minutes);
        $this->assertSame(43200.0, $summary->observed_minutes);
        $this->assertSame(0.0, $summary->measured_minutes);
        $this->assertSame(0.0, $summary->down_minutes);
        $this->assertSame(43200.0, $summary->gap_minutes);
        $this->assertLessThanOrEqual($summary->observed_minutes, $summary->gap_minutes);
    }

    /**
     * Pins the clock so the epoch grid the bucket expression floors onto is
     * deterministic. The instant is a whole five-minute boundary, which makes
     * every `subMinutes()` offset below land on a slot edge unless a test
     * deliberately pushes it off one.
     */
    protected function freezeClock(): CarbonImmutable
    {
        $now = CarbonImmutable::create(2026, 8, 1, 9, 15, 0, 'UTC');

        $this->travelTo($now);

        return $now;
    }

    /**
     * Creates a monitor owned by a freshly created team.
     */
    protected function makeMonitor(int $intervalSec = 60, ?DateTimeInterface $createdAt = null): Monitor
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

        return Monitor::query()->create(array_filter([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => $intervalSec,
            'created_at' => $createdAt,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Bulk-inserts check rows: the production-shape fixture is 767 of them, so
     * per-model creates would dominate the test's runtime.
     *
     * @param  list<array{checked_at: DateTimeInterface, status: MonitorStatus, region?: string}>  $rows
     */
    protected function makeChecks(Monitor $monitor, array $rows): void
    {
        $payload = array_map(fn (array $row): array => [
            'id' => (string) Str::orderedUuid(),
            'checked_at' => $row['checked_at']->format('Y-m-d H:i:s'),
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => $row['region'] ?? 'us-east',
            'status' => $row['status']->value,
        ], $rows);

        foreach (array_chunk($payload, 100) as $chunk) {
            MonitorCheck::query()->insert($chunk);
        }
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
