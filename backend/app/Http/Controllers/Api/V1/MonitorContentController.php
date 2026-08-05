<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMonitorMetricRequest;
use App\Http\Resources\MonitorContentVersionResource;
use App\Models\Monitor;
use App\Models\MonitorContentVersion;
use App\Services\Monitoring\ArchivedBodyReader;
use App\Services\Monitoring\ContentArchive;
use App\Services\Monitoring\MetricCandidateExtractor;
use App\Support\Monitoring\MetricCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Read side of the monitor-content archive: the list of a monitor's archived
 * page versions, the download of one version's original bytes, and the
 * extraction candidates the newest archived body was proved to contain.
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

    /**
     * Name of the limiter both archive-sample reads share: this controller's
     * candidate browser and {@see MonitorMetricController::preview()}.
     *
     * Declared here because this controller owns every archived-content read,
     * and referenced by name from `routes/api.php` and `bootstrap/app.php` so a
     * rename cannot leave one of the two routes silently unbounded. The limiter
     * is REQUIRED rather than defensive: `api/v1` never calls `throttleApi()`,
     * and one accepted request costs a cold read off a FUSE mount of a Drive
     * remote (about a second, against a remote capped near two file operations a
     * second) with an Octane worker held for the whole read.
     */
    public const string SAMPLE_READ_LIMITER = 'monitor-sample-read';

    /**
     * Cache-key prefix of one archived body's candidate digest.
     *
     * The rest of the key is the version's `content_hash` and nothing else:
     * {@see MetricCandidateExtractor} states that the same body always yields
     * the same digest, so the bytes are the whole identity of the answer. Two
     * monitors, or two teams, that archived byte-identical pages therefore share
     * one entry and can only read what their own body already produces; no part
     * of the key comes from the request.
     */
    public const string CANDIDATES_CACHE_KEY_PREFIX = 'metric-candidates:';

    /**
     * How long a candidate digest stays cached.
     *
     * An hour, chosen against both failure modes rather than as a round number.
     * A new body changes the hash and therefore the key, so a stale answer is
     * impossible by construction and the only cost of a long window is an entry
     * for a hash nobody serves any more; an hour is comfortably longer than an
     * operator's session on the metric form (which is what must not pay the cold
     * read twice) and short enough that abandoned entries expire on their own.
     */
    protected const int CANDIDATES_CACHE_TTL_SECONDS = 3600;

    /**
     * Character ceiling on a candidate's `path`.
     *
     * The number is not this endpoint's own: `extraction_path` validates at
     * `max:500` in {@see StoreMonitorMetricRequest::rules()}, and the two must
     * move together. A monitored page can name a JSON key anything, so without
     * this the browser would offer a suggestion that 422s the moment the operator
     * taps it.
     */
    protected const int MAX_CANDIDATE_PATH_LENGTH = 500;

    public function __construct(
        protected ContentArchive $archive,
        protected ArchivedBodyReader $bodyReader,
        protected MetricCandidateExtractor $candidateExtractor,
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
     * The extraction candidates the monitor's newest archived body was proved to
     * contain, as the metric form's candidate browser lists them.
     *
     * WHY THIS IS NOT THE THING {@see MonitorContentVersionResource} FORBIDS.
     * That docblock ends "Adding one is the defect this docblock exists to
     * prevent", and {@see self} opens with the same rule for the download, so the
     * argument belongs here rather than in a reviewer's head. Both invariants
     * concern a DOCUMENT being interpreted: inline `text/html` executing under
     * this authenticated origin, or markup travelling through a metadata response
     * into every JSON consumer that renders a list. This response carries neither.
     * It carries a fixed-shape digest ROW per candidate, generated by
     * {@see MetricCandidateExtractor} rather than copied out of the body, and four
     * things hold at once:
     *
     * - The same principal can already GET the whole body at
     *   `monitors/{monitor}/content/{hash}` through {@see self::show()}, so a
     *   digest is a strict subset of what they may already receive, minus the
     *   markup and minus everything the extractor did not prove.
     * - The identical digest already crosses a strictly higher-risk boundary: it
     *   is what {@see MonitorMetricController::discover()} hands to an LLM.
     * - The exposure is BOUNDED, not merely small: at most 40 rows
     *   ({@see MetricCandidateExtractor}'s `MAX_CANDIDATES`), each value cut to
     *   {@see MetricCandidate::DIGEST_VALUE_MAX_LENGTH} characters, each label
     *   dropped rather than truncated above 48, and each `path` refused above
     *   {@see self::MAX_CANDIDATE_PATH_LENGTH}.
     * - No field is a document. `sample_body` and `sample_headers` are absent
     *   here for exactly the reason they are absent from the metric preview.
     *
     * The encode sets `JSON_INVALID_UTF8_SUBSTITUTE` for the reason
     * {@see MetricCandidateExtractor::digest()} sets it: the sample values are
     * attacker-controlled text, `response()->json()` does not set the flag, and
     * Laravel's `JsonResponse` THROWS on an encode error, so one invalid byte off
     * a page with a broken charset would answer 500 instead of a list.
     */
    public function candidates(Request $request, Monitor $monitor): JsonResponse
    {
        $this->authorizeMonitor($request, $monitor);

        // 1. Name the version the reader is about to read, WITHOUT touching the
        //    archive: the cache key is that version's own content hash, and a
        //    cache hit must therefore cost nothing but this query. The scope
        //    mirrors {@see ArchivedBodyReader::newestArchivedBody()} exactly
        //    rather than adding the team column the two actions above use,
        //    because it is not an access decision (the monitor is already
        //    authorized) and a divergent pick would file one version's digest
        //    under another version's hash.
        $version = MonitorContentVersion::query()
            ->where('monitor_id', $monitor->getKey())
            ->orderByDesc('last_seen_at')
            ->first();

        if ($version === null) {
            return $this->candidatesResponse(hasSample: false, rows: []);
        }

        // 2. Sound by the extractor's own contract: the same body always yields
        //    the same digest, so an entry keyed on the bytes can never go stale,
        //    and a new body arrives under a new key. An unreadable blob answers
        //    null and is deliberately NOT cached: retention pruning it while the
        //    row survives is a transient state, and caching "no sample" for an
        //    hour would outlive it.
        $rows = Cache::remember(
            self::CANDIDATES_CACHE_KEY_PREFIX.$version->content_hash,
            self::CANDIDATES_CACHE_TTL_SECONDS,
            function () use ($monitor): ?array {
                $body = $this->bodyReader->newestArchivedBody($monitor);

                return $body === null ? null : $this->digestRows($body);
            },
        );

        return $this->candidatesResponse(hasSample: is_array($rows), rows: is_array($rows) ? $rows : []);
    }

    /**
     * Every candidate in `$body` as a digest row, minus the ones a metric write
     * would refuse.
     *
     * {@see MetricCandidateExtractor::digest()} is deliberately NOT reused: it
     * returns the prompt-shaped JSON STRING the discovery model reads, and this
     * endpoint needs the rows themselves.
     *
     * An over-long path is DROPPED rather than cut. Half an XPath expression or
     * half a dot path resolves to a different node, or to nothing, so truncating
     * one would hand the operator a suggestion that extracts silently nothing
     * forever, which is worse than not offering it.
     *
     * A drop therefore leaves a GAP in the `ref` sequence, and that is deliberate:
     * a ref names the candidate the extractor generated, so renumbering here would
     * make this endpoint and the discovery path disagree about which candidate
     * `c2` is for one identical body.
     *
     * @return list<array<string, mixed>>
     */
    protected function digestRows(string $body): array
    {
        $rows = array_map(
            fn (MetricCandidate $candidate): array => $candidate->toDigestRow(),
            $this->candidateExtractor->extract($body),
        );

        return array_values(array_filter(
            $rows,
            // `mb_strlen`, matching how Laravel's `max` rule sizes a string, so
            // the guard and the rule it defends cannot disagree on a multibyte
            // key name.
            static fn (array $row): bool => mb_strlen((string) $row['path']) <= self::MAX_CANDIDATE_PATH_LENGTH,
        ));
    }

    /**
     * The candidate browser's response envelope.
     *
     * `has_sample` mirrors what {@see MonitorMetricController::preview()} answers
     * with no sample to work from: a monitor whose archive holds nothing, or
     * whose newest blob retention already pruned, is an empty list plus a flag
     * rather than an error, so the form panel can say "nothing captured yet"
     * instead of surfacing a failure the operator cannot act on.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function candidatesResponse(bool $hasSample, array $rows): JsonResponse
    {
        return response()->json([
            'data' => $rows,
            'has_sample' => $hasSample,
        ], HttpResponse::HTTP_OK, [], JSON_INVALID_UTF8_SUBSTITUTE);
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
