<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Raises an operator-facing alarm when the content archive starts losing a
 * meaningful share of its writes.
 *
 * WHY THIS EXISTS
 *
 * Between 2026-08-25 and 2026-08-29 the archive's failure rate climbed from 6%
 * to 39% of attempts, and nothing said so. Every individual failure logs and
 * releases its claim ({@see ArchiveContent::failed()}), so the system stayed
 * consistent and quiet: a lost write reads exactly like content that had not
 * changed. The only aggregate view was the `failed_jobs` table, which nothing
 * watches. Five days of a degrading write path went unnoticed, which is what an
 * alarm is for and what this job is.
 *
 * A structured `Log::error()` IS the whole alarm, matching the house pattern for
 * a platform-level failure ({@see AlarmDarkProbeRegions}): this codebase has no
 * platform notification path and inventing one here would be a product decision
 * this job has no business making.
 *
 * EXACTLY ONCE PER CROSSING
 *
 * The same load-bearing property {@see AlarmDarkProbeRegions} has. An archive
 * that has been degraded all afternoon must not re-log on every tick, or the
 * alarm becomes noise. The guard is a cache flag rather than a column: there is
 * one archive, so the state is a single boolean and a migration for it would be
 * ceremony. It is CLEARED the moment a tick sees the rate back under the
 * threshold, so a path that degrades, recovers, and degrades again alarms twice.
 *
 * THE MINIMUM SAMPLE IS NOT OPTIONAL
 *
 * One failed write out of one attempt is a 100% failure rate and means nothing.
 * The window is skipped entirely below {@see minimumAttempts()}, which is what
 * keeps a quiet hour from paging about a single unlucky upload.
 */
class AlarmContentArchiveFailures implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The cache key holding "we have already alarmed for this degradation".
     */
    public const string ALARM_FLAG = 'content-archive:degraded';

    /**
     * One attempt per tick, for the reason {@see AlarmDarkProbeRegions::$tries}
     * gives: the next scheduled tick re-reads the same rows anyway.
     *
     * @var int
     */
    public $tries = 1;

    public function __construct()
    {
        // The housekeeping lane, beside the sibling alarm. Deliberately NOT the
        // `content` queue this job measures: a single serial worker parked in the
        // mount is exactly the state worth alarming about, and an alarm that
        // queues behind the stall it is reporting never fires.
        $this->onQueue('feeds');
    }

    /**
     * Compare the recent failure rate against the threshold and alarm on a
     * crossing.
     */
    public function handle(): void
    {
        $window = $this->windowMinutes();
        $since = now()->subMinutes($window);

        $failed = DB::table('failed_jobs')
            ->where('queue', (string) config('content-archive.queue'))
            ->where('payload', 'like', '%ArchiveContent%')
            ->where('failed_at', '>=', $since)
            ->count();

        $stored = DB::table('monitor_content_versions')
            ->where('created_at', '>=', $since)
            ->count();

        $attempts = $failed + $stored;

        if ($attempts < $this->minimumAttempts()) {
            return;
        }

        $rate = $failed / $attempts;

        if ($rate < $this->threshold()) {
            // Recovered (or never degraded). Clearing here rather than only on a
            // crossing is what lets the NEXT degradation alarm.
            Cache::forget(self::ALARM_FLAG);

            return;
        }

        if (Cache::get(self::ALARM_FLAG) === true) {
            return;
        }

        Cache::forever(self::ALARM_FLAG, true);

        Log::error('Monitor content archive is losing writes.', [
            'window_minutes' => $window,
            'attempts' => $attempts,
            'failed' => $failed,
            'stored' => $stored,
            'failure_rate' => round($rate, 3),
            'threshold' => $this->threshold(),
        ]);
    }

    /**
     * How far back a tick looks, in minutes.
     */
    protected function windowMinutes(): int
    {
        return max(1, (int) config('content-archive.alarm.window_minutes'));
    }

    /**
     * The failure share that counts as degraded, as a fraction.
     */
    protected function threshold(): float
    {
        return (float) config('content-archive.alarm.failure_rate');
    }

    /**
     * Attempts the window must hold before its rate means anything.
     */
    protected function minimumAttempts(): int
    {
        return max(1, (int) config('content-archive.alarm.minimum_attempts'));
    }
}
