<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Models\MonitorContentVersion;
use App\Services\Monitoring\ContentArchive;
use App\Support\Monitoring\NormalizedContent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves one monitor's spooled page content onto the archive mount, off the
 * check path.
 *
 * IT CARRIES A PATH, NEVER A BODY. The payload holds the spool file's location
 * plus the two hashes and nothing else. A 1 MB body in a Redis payload, times
 * every check of every monitor, is a queue that evicts its own jobs under
 * pressure, and an evicted job here means a claimed version row whose bytes
 * never arrive.
 *
 * IT RUNS ON ITS OWN QUEUE. The archive writes through an rclone FUSE mount that
 * can park a process in an uninterruptible syscall; on the shared supervisor that
 * would cost a monitoring probe. A backlog must degrade the archive and nothing
 * else.
 *
 * THE FAILURE HOOK IS THE ONLY CLEANUP PATH FOR A CLAIM. The check pipeline
 * inserts the version row before dispatching, so a write that never lands would
 * otherwise leave a row that makes every later identical body read as
 * already-archived with nothing on disk: touched forever, never pruned, and a
 * download that 404s. {@see self::failed()} deletes that row so the next check
 * re-claims and retries. `$tries = 1` is what makes the hook fire on the FIRST
 * failure, since Laravel only calls it on the attempt that exhausts the tries.
 *
 * One accepted asymmetry: a failure AFTER the bytes land but before the row is
 * finalized deletes the row and leaves the blob, which the retention sweep (it
 * iterates rows) cannot reclaim. The next check of the same content re-claims,
 * finds the blob already present, skips the write and re-creates the row, so the
 * blob becomes reclaimable again rather than staying lost.
 */
class ArchiveContent implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Queue served by its own Horizon supervisor. Read from config rather than
     * written as a literal, because the supervisor, the dev listener and the
     * check pipeline's backlog breaker all size themselves against the same key.
     */
    protected const string QUEUE_CONFIG_KEY = 'content-archive.queue';

    /**
     * One attempt, so the failure hook fires on the first failure and releases
     * the claim. Re-archiving is what a retry would buy, and the next check of
     * the same content already does exactly that.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Whole-job budget in seconds. Below the Horizon supervisor's 85s so a stalled
     * mount surfaces as this job's own failure (which runs the hook that releases
     * the claim) rather than as a worker kill, and the supervisor in turn stays
     * below the redis connection's 90s `retry_after` so a still-running write is
     * never released to a second worker.
     *
     * 80 rather than the 50 it carried until 2026-08-29, and the number comes
     * from a measurement rather than from headroom. Google Drive write latency
     * through the mount is BIMODAL: twelve 300 KB writes to distinct directories,
     * three seconds apart so rclone could not serve them warm, came back
     *
     *     min 662 ms   p50 735 ms   p90 17,329 ms   max 34,465 ms
     *
     * with four of twelve over 13 seconds. Against the old 50s budget that tail
     * was losing writes at an accelerating rate: 6% of attempts on 2026-08-25,
     * 39% by 2026-08-29, while the successful count fell from 261 a day to 116.
     * 80 absorbs the measured worst case with margin and is the largest value the
     * chain allows without moving this queue to its own connection, since
     * `retry_after` on `redis` is 90.
     *
     * If the tail grows past this again, the answer is NOT another raise: 90 is
     * the ceiling here, and the next step is taking Drive off the critical path
     * (write locally, push on a schedule) rather than a private connection for a
     * queue whose latency is not the product's to control.
     *
     * The whole chain (80 < 85 < 90) is asserted by Tests\Unit\ContentQueueConfigTest,
     * which reflects this property. Changing the number here without re-deriving
     * the chain fails that test.
     *
     * @var int
     */
    public $timeout = 80;

    /**
     * A monitor force-deleted between dispatch and handle ends the job quietly.
     *
     * The delete cascades the claimed version row away with it, so there is
     * nothing left to release; and Laravel cannot rebuild this job to call
     * {@see self::failed()} when its model is gone either way, so failing would
     * only record a defect for content nobody wants.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * @param  Monitor  $monitor  The monitor the content belongs to.
     * @param  string  $spoolPath  Local file holding the gzipped body. Deleted by
     *                             this job whether the write succeeds or fails.
     * @param  NormalizedContent  $hashes  Blob address plus change signal.
     */
    public function __construct(
        public Monitor $monitor,
        public string $spoolPath,
        public NormalizedContent $hashes,
    ) {
        $this->onQueue((string) config(self::QUEUE_CONFIG_KEY));
    }

    /**
     * Publish the spooled bytes, then take the spool file with us either way.
     *
     * The `finally` is the only thing standing between a failing archive and a
     * local disk filling up with orphaned spool files.
     */
    public function handle(ContentArchive $archive): void
    {
        try {
            $archive->store($this->monitor, $this->spoolPath, $this->hashes);
        } finally {
            $this->deleteSpoolFile();
        }
    }

    /**
     * Release the claim so a later check can re-archive this content.
     *
     * Laravel rebuilds the job from its payload to call this, so `$monitor` here
     * is freshly loaded and independent of whatever the failed attempt held.
     *
     * @param  Throwable|null  $exception  Null when the job was failed manually.
     */
    public function failed(?Throwable $exception): void
    {
        // 1. Log first, and name the monitor and the hash: without them a failed
        //    archive write is an anonymous stack trace, and the row it released is
        //    already gone by the time anyone reads it.
        Log::error('Monitor content archive write failed.', [
            'monitor_id' => $this->monitor->getKey(),
            'content_hash' => $this->hashes->rawHash,
            'normalizer_version' => $this->hashes->normalizerVersion,
            'reason' => $exception?->getMessage(),
        ]);

        // 2. Delete the claim on the full address key, the same triple
        //    ContentArchive resolves a claim by. A claim left behind with no blob
        //    is unrecoverable: nothing retries it and retention never prunes it.
        MonitorContentVersion::query()
            ->where('monitor_id', $this->monitor->getKey())
            ->where('content_hash', $this->hashes->rawHash)
            ->where('normalizer_version', $this->hashes->normalizerVersion)
            ->delete();

        // 3. Normally already gone via handle()'s `finally`; this covers the
        //    attempt that was killed before the finally could run.
        $this->deleteSpoolFile();
    }

    /**
     * Remove the spool file if it is still there.
     */
    protected function deleteSpoolFile(): void
    {
        if (is_file($this->spoolPath)) {
            unlink($this->spoolPath);
        }
    }
}
