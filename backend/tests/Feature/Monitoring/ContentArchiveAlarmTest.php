<?php

namespace Tests\Feature\Monitoring;

use App\Jobs\AlarmContentArchiveFailures;
use App\Models\MonitorContentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins the archive's failure-rate alarm.
 *
 * The alarm exists because every individual archive failure is quiet by design:
 * it logs, releases its claimed version row, and reads downstream exactly like
 * content that had not changed. Production went from 6% to 39% of writes lost
 * over five days in August 2026 with nothing saying so.
 *
 * Three properties carry the whole thing, and each has its own test below: it
 * fires on a crossing, it fires exactly ONCE per crossing, and it will not fire
 * on a sample too small to mean anything.
 */
class ContentArchiveAlarmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(AlarmContentArchiveFailures::ALARM_FLAG);

        config([
            'content-archive.alarm.window_minutes' => 60,
            'content-archive.alarm.failure_rate' => 0.15,
            'content-archive.alarm.minimum_attempts' => 20,
        ]);
    }

    public function test_a_rate_over_the_threshold_alarms(): void
    {
        $this->seedWindow(failed: 10, stored: 15);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'losing writes')
                    && $context['failed'] === 10
                    && $context['stored'] === 15
                    && $context['attempts'] === 25
                    && abs($context['failure_rate'] - 0.4) < 0.001;
            });

        (new AlarmContentArchiveFailures)->handle();

        $this->assertTrue(Cache::get(AlarmContentArchiveFailures::ALARM_FLAG));
    }

    public function test_a_rate_under_the_threshold_stays_quiet(): void
    {
        // 2 of 42 is under 5%, which is the ordinary tail of a slow upload.
        $this->seedWindow(failed: 2, stored: 40);

        Log::shouldReceive('error')->never();

        (new AlarmContentArchiveFailures)->handle();

        $this->assertNull(Cache::get(AlarmContentArchiveFailures::ALARM_FLAG));
    }

    public function test_a_sample_too_small_to_mean_anything_stays_quiet(): void
    {
        // 3 of 3 is a 100% failure rate and says nothing at all: one unlucky
        // hour with almost no traffic must not page anybody.
        $this->seedWindow(failed: 3, stored: 0);

        Log::shouldReceive('error')->never();

        (new AlarmContentArchiveFailures)->handle();

        $this->assertNull(Cache::get(AlarmContentArchiveFailures::ALARM_FLAG));
    }

    public function test_the_same_degradation_alarms_exactly_once(): void
    {
        $this->seedWindow(failed: 10, stored: 15);

        Log::shouldReceive('error')->once();

        (new AlarmContentArchiveFailures)->handle();
        (new AlarmContentArchiveFailures)->handle();
        (new AlarmContentArchiveFailures)->handle();
    }

    public function test_a_recovery_re_arms_the_alarm(): void
    {
        // Degrade, recover, degrade again: two crossings, two alarms. Without
        // the clear on the healthy tick the second outage would be silent.
        Log::shouldReceive('error')->twice();

        $this->seedWindow(failed: 10, stored: 15);
        (new AlarmContentArchiveFailures)->handle();

        DB::table('failed_jobs')->delete();
        MonitorContentVersion::query()->delete();
        $this->seedWindow(failed: 1, stored: 40);
        (new AlarmContentArchiveFailures)->handle();
        $this->assertNull(
            Cache::get(AlarmContentArchiveFailures::ALARM_FLAG),
            'a healthy tick must clear the flag, or the next outage never alarms'
        );

        DB::table('failed_jobs')->delete();
        MonitorContentVersion::query()->delete();
        $this->seedWindow(failed: 10, stored: 15);
        (new AlarmContentArchiveFailures)->handle();
    }

    public function test_rows_older_than_the_window_are_not_counted(): void
    {
        // Yesterday's outage is not this hour's rate.
        $this->seedWindow(failed: 30, stored: 0, minutesAgo: 24 * 60);
        $this->seedWindow(failed: 1, stored: 40);

        Log::shouldReceive('error')->never();

        (new AlarmContentArchiveFailures)->handle();
    }

    /**
     * Put [$failed] archive failures and [$stored] successful versions inside
     * (or outside) the alarm window.
     */
    private function seedWindow(int $failed, int $stored, int $minutesAgo = 5): void
    {
        $at = now()->subMinutes($minutesAgo);

        for ($i = 0; $i < $failed; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis',
                'queue' => (string) config('content-archive.queue'),
                'payload' => json_encode(['displayName' => 'App\Jobs\ArchiveContent']),
                'exception' => 'timed out',
                'failed_at' => $at,
            ]);
        }

        if ($stored > 0) {
            MonitorContentVersion::factory()
                ->count($stored)
                ->create(['created_at' => $at]);
        }
    }
}
