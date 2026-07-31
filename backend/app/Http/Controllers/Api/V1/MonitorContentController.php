<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MonitorContentVersionResource;
use App\Models\Monitor;
use App\Models\MonitorContentVersion;
use App\Services\Monitoring\ContentArchive;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Read side of the monitor-content archive: the list of a monitor's archived
 * page versions, and the download of one version's original bytes.
 *
 * THE BYTES THIS CONTROLLER SERVES ARE FULLY ATTACKER CONTROLLED. They are
 * whatever the monitored target chose to return, stored verbatim, and this route
 * lives on the authenticated API origin. Two consequences are load-bearing and
 * neither is optional:
 *
 * - THE DOWNLOAD NEVER RENDERS. `text/plain; charset=utf-8`,
 *   `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff` are
 *   set together on every response. Serving a monitored page's own
 *   `text/html` back inline would execute that page's script under this origin
 *   with the operator's session in scope, which is stored XSS: the archive turns
 *   any monitored target into an injection vector against the people monitoring
 *   it.
 * - A CROSS-TENANT REQUEST IS A 404, NEVER A 403. A 403 confirms that another
 *   team's monitor or version exists, which is exactly the fact being withheld.
 *   This matches every other team-scoped controller here
 *   ({@see MonitorCheckController::authorizeMonitor()}).
 *
 * The version is addressed by CONTENT HASH, and resolved by an explicit query on
 * `(monitor_id, team_id, content_hash)`. Implicit route-model binding is
 * deliberately not used: {@see MonitorContentVersion} keeps `id` as its route
 * key, so a hash in the URL would bind nothing and every request would 404. The
 * three-column key is also what makes a hash unforgeable as a handle: another
 * team's hash under a caller's own monitor matches no row, so it cannot be used
 * to reach a foreign row whose `team_id` would then derive a foreign blob path.
 *
 * The blob path is never built here. It comes from {@see ContentArchive::blobPath()},
 * the single derivation of the convention, so this reader cannot drift from the
 * writer or from the retention sweep. Nothing from the URL reaches it except a
 * hash that a stored row already matched.
 */
class MonitorContentController extends Controller
{
    /**
     * Default and maximum page size of the version index, matching
     * {@see MonitorCheckController::index()}. Bounded because an always-on
     * archive of a dynamic page accumulates one row per distinct body for the
     * whole retention window, which is not a list to return whole.
     */
    protected const int DEFAULT_PER_PAGE = 50;

    protected const int MAX_PER_PAGE = 200;

    public function __construct(
        protected ContentArchive $archive,
    ) {}

    /**
     * The monitor's archived content versions, most recently seen first.
     *
     * Metadata only: {@see MonitorContentVersionResource} carries no body field,
     * so listing versions can never leak the archived content itself.
     */
    public function index(Request $request, Monitor $monitor): AnonymousResourceCollection
    {
        $this->authorizeMonitor($request, $monitor);

        $versions = MonitorContentVersion::query()
            ->where('monitor_id', $monitor->getKey())
            ->where('team_id', $request->user()->current_team_id)
            ->orderByDesc('last_seen_at')
            ->paginate($this->resolvePerPage($request));

        return MonitorContentVersionResource::collection($versions);
    }

    /**
     * Download one archived version as an attachment carrying its original bytes.
     *
     * @param  string  $contentHash  The version's address. Route-constrained to 64
     *                               lowercase hex characters, so a malformed one
     *                               404s at routing rather than reaching
     *                               {@see ContentArchive::blobPath()}, which
     *                               throws on it.
     */
    public function show(Request $request, Monitor $monitor, string $contentHash): Response
    {
        $this->authorizeMonitor($request, $monitor);

        // 1. Resolve the version explicitly, scoped to the addressed monitor AND
        //    the caller's current team. No route-model binding: `id` is this
        //    model's route key, so a hash would bind nothing.
        $version = MonitorContentVersion::query()
            ->where('monitor_id', $monitor->getKey())
            ->where('team_id', $request->user()->current_team_id)
            ->where('content_hash', $contentHash)
            ->first();

        abort_if($version === null, HttpResponse::HTTP_NOT_FOUND);

        // 2. Read through the archive's own path helper, from the ROW's team and
        //    hash. A single read rather than an exists-then-read pair: the target
        //    is a FUSE mount of a remote, so one round trip is the cheaper answer
        //    and it cannot lose a race to the retention sweep between the two
        //    calls. A null or empty read means retention already pruned the blob
        //    while check rows still carry the hash, which is an ordinary 404 and
        //    must never surface as a 500 mid-response.
        $compressed = Storage::disk((string) config('content-archive.disk'))
            ->get($this->archive->blobPath($version->team_id, $version->content_hash));

        abort_if(! is_string($compressed) || $compressed === '', HttpResponse::HTTP_NOT_FOUND);

        // 3. A blob that will not decompress is reachable, not hypothetical: every
        //    local user can write to the archive mount (rclone enforces no POSIX
        //    permissions without `--default-permissions`), so garbage can sit at a
        //    content-addressed path. Guard the `false` explicitly rather than
        //    casting it: `(string) false` would serve a 200 carrying an empty
        //    attachment labelled as archived content, and leaving the warning
        //    unhandled would surface the 500 the pruned-blob branch above exists to
        //    avoid. Same shape as the sibling reader in `MonitorMetricController`.
        $body = gzdecode($compressed);

        abort_if($body === false, HttpResponse::HTTP_NOT_FOUND);

        return response($body, HttpResponse::HTTP_OK, [
            // Never `text/html`, whatever the target served: the archived
            // `content_type` is untrusted input and is reported in the index, not
            // echoed into this header.
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$this->downloadName($monitor, $version).'"',
            // The third leg of the same guarantee: a browser that sniffs past a
            // `text/plain` label would undo the two headers above.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Filename the download lands on, built from the monitor's key and the
     * version's hash so an exported blob stays identifiable.
     *
     * Assembled literally rather than through a disposition encoder because both
     * halves are already constrained: the key comes from this database via route
     * binding and the hash matched a stored 64-hex address. NO client-supplied
     * filename, path or disk name participates.
     */
    protected function downloadName(Monitor $monitor, MonitorContentVersion $version): string
    {
        return 'monitor-'.$monitor->getKey().'-'.$version->content_hash.'.txt';
    }

    /**
     * Aborts with 404 when the monitor does not belong to the caller's current
     * team, so a stray id never leaks cross-tenant data and never confirms that
     * the monitor exists.
     */
    protected function authorizeMonitor(Request $request, Monitor $monitor): void
    {
        abort_unless(
            $monitor->team_id === $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }

    /**
     * Clamp the caller's `per_page` into the supported window.
     */
    protected function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', (string) self::DEFAULT_PER_PAGE);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }
}
