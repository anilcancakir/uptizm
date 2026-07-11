<?php

namespace App\Jobs;

use App\Models\Monitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Daily fan-out job that queues one {@see PerformSslCheck} per monitor with
 * SSL tracking enabled on an https target.
 *
 * One isolated job is dispatched per monitor (never a single batch loop) so a
 * slow or unreachable host fails only its own check and leaves the rest of the
 * fleet untouched.
 */
class ScheduleSslChecks implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Seconds for which only one copy of this job may run, guarding against an
     * overlapping daily tick while a prior fan-out is still enqueuing.
     *
     * @var int
     */
    public $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('ssl');
    }

    public function handle(): void
    {
        // 1. Select every https monitor opted in to SSL tracking.
        $monitorIds = Monitor::query()
            ->where('ssl_tracking', true)
            ->where('url', 'like', 'https://%')
            ->pluck('id');

        // 2. Fan out one isolated job per monitor for failure isolation.
        foreach ($monitorIds as $monitorId) {
            PerformSslCheck::dispatch((string) $monitorId)->onQueue('ssl');
        }
    }
}
