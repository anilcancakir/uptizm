<?php

namespace App\Jobs;

use App\Models\MonitorContentVersion;
use App\Services\Monitoring\ContentArchive;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Nightly retention sweep for the monitor-content archive: it expires every
 * version row whose content nothing has resolved to inside the window, then
 * reclaims the blob once no row references it any more.
 *
 * Four decisions carry this job, and each of them is a way to delete bytes a
 * live check still points at.
 *
 * `last_seen_at` IS THE ONLY AGE THAT MEANS ANYTHING HERE. Not `first_seen_at`,
 * not the blob's mtime, not the age of the check that recorded the hash. A page
 * that changed once and then stayed stable has a SINGLE version row that is
 * ancient and current at the same time: first seen months ago, and still what
 * every check today resolves to. changedetection.io's `history_trim` keys on the
 * file instead and deletes exactly that entry; because its reader skips a missing
 * file without complaining, the symptom is a history entry quietly vanishing
 * rather than an error anyone can act on.
 *
 * ROW FIRST, THEN THE SURVIVOR QUERY, THEN THE BLOB. There is exactly one correct
 * order. Deleting the row first is what lets the survivor check be a plain
 * existence query: blob-first would force it to exclude the row being expired,
 * and a check that includes itself always finds itself, so no blob would ever be
 * deletable and retention would silently no-op while the mount grew without
 * bound.
 *
 * A BLOB AND A ROW ARE KEYED DIFFERENTLY. The file is addressed by
 * `(team_id, content_hash)` while a row is keyed by
 * `(monitor_id, content_hash, normalizer_version)`, so several rows legitimately
 * address ONE file: two monitors in a team serving identical content, or the same
 * body re-archived under a bumped `normalizer_version` (where the archive skips
 * the write because the target is already there). Deleting the blob per expired
 * row is the changedetection.io defect again, reintroduced through a key mismatch
 * instead of an age comparison, so the survivor query keys on exactly what the
 * PATH is derived from and nothing else.
 *
 * THE PATH COMES FROM {@see ContentArchive::blobPath()} AND NOWHERE ELSE. It owns
 * the `(team_id, content_hash)` derivation and the 64-lowercase-hex validation,
 * and this is the only code in the system that hands a path to a DELETE against a
 * remote that also holds this system's only PostgreSQL backups. A row whose hash
 * the helper rejects is left completely alone: no blob delete, and no row delete
 * either, because a row that cannot be reduced to a path cannot be reclaimed and
 * half-expiring it would only lose the record of what is on the mount.
 *
 * It runs on the archive's own queue rather than the shared supervisor for the
 * reason the write path does: an unlink through an rclone FUSE mount can park a
 * process in an uninterruptible syscall, and on supervisor-1 that stall would
 * occupy a slot the monitoring checks need. The archive degrading for a minute a
 * night is acceptable; monitoring degrading is not. The sweep is resumable
 * (every row is expired independently and durably), so a run cut short by the
 * job timeout simply continues on the next night rather than starting over.
 *
 * ACCEPTED, AND NOT FIXABLE HERE: a FUSE unlink cannot pass
 * `--drive-use-trash=false` and the mount was started without it, so every delete
 * below lands in the Drive remote's trash and only frees quota when Drive
 * auto-purges it 30 days later. Emptying that trash by hand is NOT an option: it
 * is shared with the PostgreSQL backups.
 */
class PruneContentArchive implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Lock key for the whole sweep. Public because the test that proves a second
     * sweep bows out has to hold the same lock from outside.
     */
    public const string LOCK_KEY = 'prune-content-archive';

    /**
     * Time-to-live (seconds) of that lock. Comfortably longer than any sweep the
     * job timeout below can allow, and far shorter than the daily cadence, so a
     * worker killed mid-sweep releases its hold long before the next run instead
     * of skipping a night.
     */
    protected const int LOCK_TTL_SECONDS = 3600;

    /**
     * Rows held in memory at once. `chunkById` keyset-paginates, which is what
     * makes it safe to DELETE inside the loop: an offset-paginated chunk would
     * skip a row for every row removed.
     */
    protected const int CHUNK_SIZE = 200;

    /**
     * One attempt. A retry would re-derive the same cutoff and re-visit the rows
     * the interrupted run had not reached yet, which is exactly what the next
     * nightly run does anyway.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Whole-sweep budget in seconds, below the `content` supervisor's 60s so an
     * unlink parked in the mount surfaces as this job's own timeout rather than as
     * a worker kill.
     *
     * @var int
     */
    public $timeout = 50;

    public function __construct()
    {
        $this->onQueue((string) config('content-archive.queue'));
    }

    /**
     * Expire every version outside the retention window.
     *
     * @param  ContentArchive  $archive  Consulted only for the blob path, which is
     *                                   derived and validated in exactly one place.
     *
     * @throws RuntimeException When the configured retention window is not a
     *                          positive number of days.
     */
    public function handle(ContentArchive $archive): void
    {
        // 1. Resolve the cutoff BEFORE acquiring anything. A window of zero days
        //    puts the cutoff at `now()` and expires every version ever archived in
        //    a single sweep, so it has to fail before the first delete rather than
        //    after the last one.
        $cutoff = $this->cutoff();

        // 2. The schedule already carries `withoutOverlapping()`, but that guard
        //    only covers scheduled runs. Two concurrent sweeps would race the
        //    survivor query: each could see a row the other is about to delete,
        //    both would conclude the blob is still referenced, and the file would
        //    outlive every row that named it with nothing left to reclaim it.
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->sweep($archive, $cutoff);
        } finally {
            $lock->release();
        }
    }

    /**
     * Walk the expired rows in bounded chunks and expire each one.
     */
    protected function sweep(ContentArchive $archive, CarbonInterface $cutoff): void
    {
        MonitorContentVersion::query()
            ->where('last_seen_at', '<', $cutoff)
            ->chunkById(self::CHUNK_SIZE, function (Collection $expired) use ($archive): void {
                foreach ($expired as $version) {
                    $this->expire($archive, $version);
                }
            });
    }

    /**
     * Expire one version: its row, and its blob when nothing else claims it.
     */
    protected function expire(ContentArchive $archive, MonitorContentVersion $version): void
    {
        // 1. Derive the delete target first, because the derivation IS the
        //    validation. A hash the helper rejects leaves this row untouched.
        $path = $this->blobPath($archive, $version);

        if ($path === null) {
            return;
        }

        // 2. ROW FIRST, so the survivor query below can be a plain existence
        //    check. See the class docblock: the other order is not a style
        //    preference, it makes the check either self-inclusive or wrong.
        $version->delete();

        // 3. Keyed on `(team_id, content_hash)`, the same pair the path is derived
        //    from: a sibling monitor in this team, or the same body under another
        //    normalizer generation, still holds these exact bytes.
        if ($this->blobIsStillReferenced($version)) {
            return;
        }

        // 4. The content disk is configured `throw => false`, so a failed unlink
        //    returns false. Ignoring that would leave a file no row references and
        //    nothing to find it by, since no path is ever stored.
        if ($this->disk()->delete($path) === false) {
            Log::warning('Failed to delete an expired content blob.', [
                'team_id' => $version->team_id,
                'content_hash' => $version->content_hash,
            ]);
        }

        // 5. Re-run the survivor check AFTER the unlink, because step 3 and step 4
        //    are not atomic and the archive write path can slip between them.
        //    `ContentArchive::store()` skips the write when the target already
        //    exists, so a check whose content reverts to the version being expired
        //    right here can claim, see the file that is about to vanish, skip the
        //    write, and touch its row. The unlink then lands and leaves a LIVE row
        //    with no bytes: touched forever, never pruned, download 404s, discovery
        //    reads nothing. That is the one state this whole design is built to make
        //    impossible, and it is the only door into it that neither the claim nor
        //    the archive job can see.
        //
        //    Detect and repair rather than lock: a per-address lock would put a
        //    correctness invariant on a 512 MB `volatile-lru` Redis shared with a
        //    cache, where lock keys carry a TTL and are eviction candidates. Deleting
        //    the row that raced in costs one archived version and the next check
        //    re-claims it cleanly; leaving it costs the version permanently.
        $raced = MonitorContentVersion::query()
            ->where('team_id', $version->team_id)
            ->where('content_hash', $version->content_hash)
            ->get();

        if ($raced->isNotEmpty()) {
            Log::warning('A content version claimed the blob this sweep was deleting; releasing it so the next check re-archives.', [
                'team_id' => $version->team_id,
                'content_hash' => $version->content_hash,
                'released_rows' => $raced->count(),
            ]);

            MonitorContentVersion::query()
                ->whereKey($raced->modelKeys())
                ->delete();
        }
    }

    /**
     * Whether any surviving row still addresses this expired row's blob.
     *
     * Runs AFTER the row is deleted, so it needs no exclusion clause.
     */
    protected function blobIsStillReferenced(MonitorContentVersion $expired): bool
    {
        return MonitorContentVersion::query()
            ->where('team_id', $expired->team_id)
            ->where('content_hash', $expired->content_hash)
            ->exists();
    }

    /**
     * The blob this version addresses, or null when its hash cannot address one.
     *
     * The helper documents the rejection as the signal a caller iterating stored
     * rows is meant to skip on, and skipping is the only safe answer: a stored
     * value that is not 64 lowercase hex characters was never written by the
     * archive, so guessing at a path for it would aim a delete at something this
     * feature does not own.
     */
    protected function blobPath(ContentArchive $archive, MonitorContentVersion $version): ?string
    {
        try {
            return $archive->blobPath($version->team_id, $version->content_hash);
        } catch (InvalidArgumentException $e) {
            Log::warning('Skipped a content version whose hash cannot address a blob.', [
                'version_id' => $version->getKey(),
                'team_id' => $version->team_id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Start of the retention window: anything last seen before this is expired.
     *
     * @throws RuntimeException When `retention_days` is not at least one day. It
     *                          is env-driven, and at zero every version ever
     *                          archived is outside the window, so a single bad
     *                          value would empty the archive in one sweep. A
     *                          failed job is visible in Horizon; a silent mass
     *                          delete against the mount holding the only
     *                          PostgreSQL backups is not.
     */
    protected function cutoff(): CarbonInterface
    {
        $days = (int) config('content-archive.retention_days');

        if ($days < 1) {
            throw new RuntimeException(
                'content-archive.retention_days resolved to ['.$days.'], which expires every '
                .'archived version at once; the retention sweep needs a window of at least one day.'
            );
        }

        return now()->subDays($days);
    }

    /**
     * The archive disk, resolved per call rather than injected: the mount can go
     * away under a long-lived worker, and the test suite swaps this disk for a
     * fake after the job is already constructed.
     */
    protected function disk(): Filesystem
    {
        return Storage::disk((string) config('content-archive.disk'));
    }
}
