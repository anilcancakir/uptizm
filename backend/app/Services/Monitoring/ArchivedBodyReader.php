<?php

namespace App\Services\Monitoring;

use App\Models\Monitor;
use App\Models\MonitorContentVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Read side of the monitor-content archive for the callers that want the BODY
 * rather than a download: the metric form's extraction preview, AI metric
 * discovery, and the extraction-candidate browser.
 *
 * A service rather than a private method on one of them because all three need
 * the identical answer, and the two details below are exactly the kind that
 * drift once they are written twice.
 */
class ArchivedBodyReader
{
    public function __construct(
        protected ContentArchive $archive,
    ) {}

    /**
     * The decompressed body of the monitor's NEWEST archived content version, or
     * null when there is none to read.
     *
     * Only the newest, deliberately: the archive lives on a cold FUSE mount of a
     * Drive remote where a single read costs about a second and the remote caps
     * at roughly two files per second, so a history-wide scan would turn one
     * request into a stall.
     */
    public function newestArchivedBody(Monitor $monitor): ?string
    {
        $version = $this->newestVersion($monitor);

        return $version === null ? null : $this->bodyForVersion($monitor, $version);
    }

    /**
     * The monitor's newest archived content version, or null when it has none.
     *
     * Exposed separately for the caller that needs to NAME the version before
     * reading it: the candidate browser keys an hour-long cache entry on the
     * version's content hash, and resolving the newest version twice (once for
     * the key, once inside the reader) files one version's digest under another
     * version's hash whenever a check completes between the two queries. One
     * resolution handed to {@see self::bodyForVersion()} closes that window, and
     * keeps this scope defined in exactly one place.
     */
    public function newestVersion(Monitor $monitor): ?MonitorContentVersion
    {
        return MonitorContentVersion::query()
            ->where('monitor_id', $monitor->getKey())
            ->orderByDesc('last_seen_at')
            ->first();
    }

    /**
     * The decompressed body behind one already-resolved `$version`.
     *
     * Every failure mode answers null rather than throwing, because every caller
     * is a read-only convenience: a version whose blob retention already pruned,
     * a corrupt stored hash the path helper refuses, and bytes that will not
     * decompress all mean "no sample available", never a 500 on a form panel.
     *
     * `$monitor` is carried for the log context only; the bytes are addressed by
     * the version's team and hash.
     */
    public function bodyForVersion(Monitor $monitor, MonitorContentVersion $version): ?string
    {
        try {
            // The single permitted derivation of a blob location; no caller
            // rebuilds `{team}/{fanout}/{hash}.gz` for itself.
            $path = $this->archive->blobPath($version->team_id, (string) $version->content_hash);
        } catch (InvalidArgumentException $exception) {
            // The corrupt-row case the retention sweep also skips. Logged rather
            // than swallowed: the row needs a human, the request does not.
            Log::warning('Skipped an archived content version with a malformed hash.', [
                'monitor_id' => (string) $monitor->getKey(),
                'version_id' => (string) $version->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        // One `get()` rather than `exists()`-then-`get()`: two round trips to a
        // FUSE mount can also lose the race to the retention sweep between them.
        $blob = Storage::disk((string) config('content-archive.disk'))->get($path);
        if ($blob === null || $blob === '') {
            return null;
        }

        $body = gzdecode($blob);

        return $body === false ? null : $body;
    }
}
