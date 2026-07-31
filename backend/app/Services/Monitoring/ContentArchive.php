<?php

namespace App\Services\Monitoring;

use App\Models\Monitor;
use App\Models\MonitorContentVersion;
use App\Support\Monitoring\NormalizedContent;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Write side of the monitor-content archive: it publishes one gzipped blob per
 * distinct page content and finalizes the version row that claimed it.
 *
 * The claim is what makes this class small. The check pipeline INSERTS the
 * version row before dispatching the write, so exactly one writer per address
 * ever reaches {@see self::store()}; it therefore finalizes and never inserts.
 * A row this class created would be a row no claim guarded, which is the
 * orphaned-blob path the claim model exists to close.
 *
 * Three invariants live here and nowhere else:
 *
 * - THE PATH. {@see self::blobPath()} is the only derivation of a blob's
 *   location, and it validates the hash before returning one. The retention
 *   sweep and the download endpoint both call it rather than re-deriving,
 *   because that path is the argument to a DELETE against a remote that also
 *   holds this system's only PostgreSQL backups. Nothing but the team id and a
 *   64-character lowercase hex hash ever reaches it: no header, no URL, no
 *   stored string.
 * - `byte_size` IS THE RAW LENGTH. The claim writes it from the decoded body
 *   and this class never rewrites it. The gzipped blob is roughly an eighth of
 *   that, so a single overwrite would leave one column meaning two different
 *   things depending on which path touched the row last.
 * - THE WRITE IS IDEMPOTENT AND CRASH-SAFE. An existing target is left alone
 *   (the address proves the contents), and a new one is staged under a
 *   per-process name and renamed, so a worker killed mid-write cannot leave a
 *   truncated file at a content-addressed path that every later write skips.
 *
 * The target is an rclone FUSE mount of a Google Drive remote. Two consequences
 * shape the code: a write can sit in an uninterruptible syscall past the
 * worker's own timeout, so retry-safety cannot rest on that timeout, and a DEAD
 * mount presents as an ordinary empty directory, which the `local` driver would
 * happily fill on the underlying volume instead of failing. Hence the staging
 * rename and the sentinel probe.
 */
class ContentArchive
{
    /**
     * The only accepted shape of a blob address. Lowercase because `hash()`
     * emits lowercase and a case-folded compare would let one body address two
     * files; anchored because length alone does not exclude a traversal segment.
     */
    protected const string HASH_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * Blob suffix. gzip, not brotli: `brotli_compress` does not exist on the
     * production PHP, where only zlib is loaded.
     */
    protected const string BLOB_EXTENSION = '.gz';

    /**
     * Characters of the hash used as a fan-out directory, so one team's blobs
     * never land in a single directory on a remote that lists slowly.
     */
    protected const int FANOUT_LENGTH = 2;

    /**
     * Relative storage key of a version's blob, on the pinned content disk.
     *
     * The single definition of the path convention: the write below, the
     * retention sweep and the download endpoint all resolve here, so no two of
     * them can drift.
     *
     * @param  int|string  $teamId  Owning team's primary key. A key from this
     *                              database, never client input.
     * @param  string  $contentHash  Raw SHA-256 hex of the stored bytes.
     *
     * @throws InvalidArgumentException When the hash is not 64 lowercase hex
     *                                  characters. A caller iterating stored
     *                                  rows is expected to skip on this rather
     *                                  than guess at a path.
     */
    public function blobPath(int|string $teamId, string $contentHash): string
    {
        if (preg_match(self::HASH_PATTERN, $contentHash) !== 1) {
            throw new InvalidArgumentException(
                'A content-blob path takes a 64-character lowercase hex SHA-256 and nothing else.'
            );
        }

        return $teamId
            .'/'.substr($contentHash, 0, self::FANOUT_LENGTH)
            .'/'.$contentHash.self::BLOB_EXTENSION;
    }

    /**
     * Publish the spooled bytes and finalize the row that claimed them.
     *
     * @param  Monitor  $monitor  The monitor whose content this is.
     * @param  string  $spoolPath  Local file holding the ALREADY-gzipped body.
     *                             The caller owns its lifecycle; this method
     *                             only reads it.
     * @param  NormalizedContent  $hashes  Address plus change signal for the body.
     *
     * @throws RuntimeException When the mount is not live, no claim row exists,
     *                          the spool is unreadable, or the write fails. Every
     *                          one of these has to throw rather than return: the
     *                          job's failure hook is the only thing that releases
     *                          the claim, and a graceful abort would leave a row
     *                          with no blob behind it, which reads as
     *                          already-archived forever.
     */
    public function store(Monitor $monitor, string $spoolPath, NormalizedContent $hashes): void
    {
        // 1. Prove the mount before touching it. A `local` driver aimed at a dead
        //    FUSE mount writes to the underlying volume and reports success.
        $this->assertMountIsLive();

        // 2. Resolve the claim BEFORE writing anything. No claim means no writer
        //    was elected, so bytes written here would answer to nothing.
        $version = $this->claimedVersion($monitor, $hashes);

        if ($version === null) {
            throw new RuntimeException(
                'No claimed content version for monitor ['.$monitor->getKey().'] at hash ['
                .$hashes->rawHash.']; the archive finalizes a claim and never creates one.'
            );
        }

        // 3. Content-addressed, so an existing target already holds exactly these
        //    bytes and rewriting it would only spend mount throughput. The path
        //    derives from the ROW's team, the same column the retention sweep and
        //    the download endpoint will derive from later.
        $path = $this->blobPath($version->team_id, $hashes->rawHash);

        if (! $this->disk()->fileExists($path)) {
            $this->publishBlob($path, $this->readSpool($spoolPath));
        }

        // 4. Finalize: `last_seen_at` and nothing else. `byte_size` still holds
        //    the RAW decoded length the claim wrote.
        $this->touch($version);
    }

    /**
     * Advance a version's `last_seen_at`, the metadata-only path taken when a
     * check resolves to content that is already archived.
     *
     * It takes the resolved MODEL rather than a hash on purpose. On the unchanged
     * path the current body's raw hash is NOT this row's address (the row is
     * addressed by the hash of the bytes actually stored), so a hash parameter
     * would invite an update that matches nothing, freezing `last_seen_at` until
     * retention deleted a version every live check still points at.
     *
     * @param  MonitorContentVersion  $version  A row as read from the database.
     */
    public function touch(MonitorContentVersion $version): void
    {
        $version->update([
            'last_seen_at' => now(),
        ]);
    }

    /**
     * The row the check pipeline claimed for these bytes, or null when none is.
     *
     * Keyed on the full address `(monitor_id, content_hash, normalizer_version)`,
     * the unique index the claim collides on. The version participates because a
     * normalization bump legitimately re-archives an identical body under a
     * second row: a two-column lookup could then finalize the older generation's
     * row and leave the new one frozen.
     */
    protected function claimedVersion(Monitor $monitor, NormalizedContent $hashes): ?MonitorContentVersion
    {
        return MonitorContentVersion::query()
            ->where('monitor_id', $monitor->getKey())
            ->where('content_hash', $hashes->rawHash)
            ->where('normalizer_version', $hashes->normalizerVersion)
            ->first();
    }

    /**
     * Fail unless the configured sentinel file is present on the disk.
     *
     * @throws RuntimeException When the sentinel is configured and absent.
     */
    protected function assertMountIsLive(): void
    {
        $sentinel = config('content-archive.sentinel');

        // Null (the default) and an empty string (what a bare `KEY=` in a .env
        // resolves to) both mean the guard is off. The local root is an ordinary
        // directory nobody seeds, so a non-empty default would abort every write
        // in development; production sets a real filename.
        if (! is_string($sentinel) || $sentinel === '') {
            return;
        }

        if ($this->disk()->fileExists($sentinel)) {
            return;
        }

        throw new RuntimeException(
            'The content archive mount is not live: sentinel ['.$sentinel.'] is missing from the ['
            .$this->diskName().'] disk.'
        );
    }

    /**
     * Write the bytes to a per-process staging key, then rename onto the address.
     *
     * @throws RuntimeException When either step fails.
     */
    protected function publishBlob(string $path, string $bytes): void
    {
        $disk = $this->disk();
        $staging = $this->stagingPath($path);

        // The content disk is configured `throw => false`, so a failed write
        // returns false. Ignoring that return would advance `last_seen_at` for a
        // version whose bytes never landed.
        if ($disk->put($staging, $bytes) === false) {
            throw new RuntimeException('Failed to stage a content blob at ['.$staging.'].');
        }

        if ($disk->move($staging, $path) === false) {
            // Do not leave the staging file behind; nothing else ever collects it.
            $disk->delete($staging);

            throw new RuntimeException('Failed to publish a content blob at ['.$path.'].');
        }
    }

    /**
     * Staging key for a blob: `{hash}.{pid}.gz.tmp` beside its final name.
     *
     * PER PROCESS, not one shared name per address. The claim is per monitor, so
     * two monitors in one team serving byte-identical content legitimately
     * produce two writers for the same address; interleaved writes into a single
     * staging file would rename a corrupted blob onto a content-addressed name
     * that every later write then skips forever.
     */
    protected function stagingPath(string $blobPath): string
    {
        // `getmypid()` is documented as int|false; a platform without process ids
        // still needs a name unique to this writer.
        $writer = getmypid() ?: Str::random(8);

        return Str::beforeLast($blobPath, self::BLOB_EXTENSION)
            .'.'.$writer.self::BLOB_EXTENSION.'.tmp';
    }

    /**
     * The gzipped body the caller spooled to local disk.
     *
     * @throws RuntimeException When the spool file is missing or empty. Empty is
     *                          as fatal as missing: a zero-length blob at a
     *                          content-addressed path is permanent, because every
     *                          later write of the same content skips it.
     */
    protected function readSpool(string $spoolPath): string
    {
        $bytes = is_file($spoolPath) ? file_get_contents($spoolPath) : false;

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('The content spool file ['.$spoolPath.'] is missing or empty.');
        }

        return $bytes;
    }

    /**
     * The archive disk, pinned by its own config key.
     */
    protected function diskName(): string
    {
        return (string) config('content-archive.disk');
    }

    /**
     * The archive disk, resolved per call rather than injected: the mount can go
     * away under a long-lived worker, and the test suite swaps this disk for a
     * fake after the service is already constructed.
     */
    protected function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }
}
