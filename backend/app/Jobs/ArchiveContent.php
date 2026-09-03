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
     * The connection this job rides in production, whose `retry_after` (300) is
     * the wall its 270s budget fits under. The shared `redis` connection's 90
     * cannot hold it.
     *
     * A constant AND the config key below, which looks like one place too many
     * and is not. {@see Tests\Unit\JobTimeoutFitsItsConnectionTest} reads this
     * constant by reflection to check every job's budget against its connection,
     * and it cannot read a `config()` call made inside a constructor; that guard
     * is repo-wide and it caught this job the moment the constant went away.
     */
    protected const string CONNECTION = 'redis-content';

    /**
     * The connection key the constructor actually reads, defaulting to
     * {@see self::CONNECTION}.
     *
     * The override exists to keep the suite honest. `phpunit.xml` pins
     * `QUEUE_CONNECTION=sync` so a dispatched archive runs inline and the tests
     * that assert a blob landed actually exercise the write. Naming a redis
     * connection unconditionally overrides that, the dispatch enqueues instead
     * of running, and thirteen tests go quietly green over an archive that never
     * happened. That is not hypothetical: it is what the first version of this
     * change did.
     *
     * The two cannot drift: ContentQueueConfigTest pins the constant against the
     * supervisor's own connection, which is the one that owns `retry_after`.
     */
    protected const string CONNECTION_CONFIG_KEY = 'content-archive.connection';

    /**
     * One attempt, so the failure hook fires on the first failure and releases
     * the claim. Re-archiving is what a retry would buy, and the next check of
     * the same content already does exactly that.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Whole-job budget in seconds. Below the Horizon supervisor's 280s so a
     * stalled mount surfaces as this job's own failure (which runs the hook that
     * releases the claim) rather than as a worker kill, and the supervisor in
     * turn stays below the `redis-content` connection's 300s `retry_after` so a
     * still-running write is never released to a second worker.
     *
     * THIS NUMBER HAS BEEN WRONG TWICE, AND BOTH TIMES FOR THE SAME REASON
     *
     * It was 50, then 80 on 2026-08-29 when the failure rate had climbed from 6%
     * to 39%. That raise worked (the rate fell to about 2%) and it was aimed at
     * the wrong thing. The measurement behind it timed WRITES and found them
     * bimodal, p50 735 ms against a max of 34.5 s, and concluded the budget had
     * to cover a slow write.
     *
     * Measured again on 2026-09-01, one layer down, the write was never the cost:
     * it lands in rclone's VFS cache in about 8 ms. What the old probe was
     * actually timing was the DIRECTORY LISTING each write to a fresh directory
     * forced. Timed on its own: 16.9 s and 16.3 s cold, 0.2 ms once rclone has
     * the directory, while Google Drive was rate limiting the account throughout
     * (309 x 429, 246 x 503 and 71 x rateLimitExceeded in 20,000 log lines).
     * A rate-limited listing retries with backoff and has no bound worth naming,
     * which is why 80 seconds still lost 5 to 7 versions a day.
     *
     * The prior version of this docblock said the answer to that was NOT another
     * raise, and it was right about the raise and wrong about the reason. The
     * listing this job spent on every archive was avoidable: it came from asking
     * the mount whether the blob was already there, a question answered "no" by
     * construction and worth 0.12% of writes. {@see ContentArchive::store()} no
     * longer asks it, and THAT is taking Drive off the critical path. This budget
     * covers what remains, the sentinel probe and the staging rename, which are
     * still Drive operations and can still be rate limited.
     *
     * So the private connection the old note argued against is here, and it is
     * deliberately the smaller half of the fix. A budget alone would have been
     * the third version of the same mistake.
     *
     * A raise is not free at one process: it converts a lost archive into a lane
     * blocked for the length of the budget. The supervisor runs two now, and that
     * pairing is the point rather than two independent edits.
     *
     * The whole chain (270 < 280 < 300) is asserted by
     * Tests\Unit\ContentQueueConfigTest, which reflects this property. Changing
     * the number here without re-deriving the chain fails that test.
     *
     * @var int
     */
    public $timeout = 270;

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
        // BOTH, for the reason PublishAiIncidentUpdate states at its own pair:
        // the two redis connections share one list namespace, so the queue name
        // decides which list the job lands in and the connection decides whose
        // `retry_after` governs it. Naming one without the other puts the work in
        // front of a worker sized by a different number.
        $this->onConnection((string) config(self::CONNECTION_CONFIG_KEY, self::CONNECTION));
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
