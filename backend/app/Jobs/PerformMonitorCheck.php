<?php

namespace App\Jobs;

use App\Enums\MonitorRegion;
use App\Models\Monitor;
use App\Models\MonitorContentVersion;
use App\Services\Monitoring\ContentArchive;
use App\Services\Monitoring\LocalProbeEngine;
use App\Services\Monitoring\MetricExtractor;
use App\Services\Monitoring\ProbeTransport;
use App\Services\Monitoring\RelayClient;
use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\ContentNormalizer;
use App\Support\Monitoring\ContentTypeAllowList;
use App\Support\Monitoring\NormalizedContent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use Throwable;

/**
 * Executes one probe for a (monitor, region) pair through the {@see ProbeTransport}
 * that monitor is allowed on, and hands the parsed result to
 * {@see ProcessCheckResult} for persistence.
 *
 * Both transports return the outcome inline, so this job hands the
 * already-fetched result straight to the processing queue instead of waiting on
 * an async callback. It owns the network round-trip, metric extraction and the
 * content-archive decision, because it is the only stage that holds the full
 * response body; any persistence or threshold side-effect lives downstream so a
 * worker failure retries cheaply.
 *
 * WHICH NETWORK RUNS THE PROBE IS DECIDED HERE, per monitor, and not at enqueue
 * time. The manual test endpoint (`POST api/v1/monitors/{id}/test`) dispatches
 * this job directly, so a decision taken by {@see ScheduleMonitorChecks} would
 * simply not exist on that path. See {@see self::transportFor()}.
 *
 * THE ARCHIVE DECISION IS A CLAIM, NOT A READ-THEN-DECIDE. Two regions of one
 * monitor fan out in the same tick ({@see ScheduleMonitorChecks}) and a page that
 * churns a per-request token gives them DIFFERENT raw hashes for the SAME logical
 * content. Under a read-then-decide both would find no version row, both would
 * write, and the second row insert would collide on the normalized unique index,
 * fail under the archive job's single try, and leave behind a blob no version row
 * addresses: the retention sweep iterates rows, so nothing would ever reclaim it.
 * {@see self::claimContentVersion()} therefore makes the decision and the mutual
 * exclusion one `insertOrIgnore`, and only the winner ever writes bytes.
 *
 * THE ARCHIVE DEGRADES, THE MONITORING NEVER DOES. Everything the decision
 * touches (a queue-size probe, a claim insert, a local spool write, a dispatch)
 * is wrapped so its failure costs the content and nothing else; the check row and
 * its threshold evaluation are downstream of this job and must still happen.
 *
 * One accepted interleaving: `$tries = 3` re-probes on retry, so a retried check
 * of a page carrying a per-request token can archive the same logical page under
 * a second raw hash. It is bounded by the try count and reclaimed by the
 * last-seen retention window.
 */
class PerformMonitorCheck implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * `tempnam()` prefix for the spooled gzipped body. The archive job deletes
     * the file it is handed, whether its write succeeds or fails.
     */
    protected const string SPOOL_PREFIX = 'uptizm-content-';

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @var int
     */
    public $backoff = 10;

    /**
     * @param  Monitor  $monitor  The monitor to probe.
     * @param  string  $region  Target region value (see {@see MonitorRegion}).
     */
    public function __construct(
        public Monitor $monitor,
        public string $region,
    ) {
        $this->onQueue('checks');
    }

    /**
     * @param  ProbeTransport  $relay  The DEFAULT transport, which the container
     *                                 resolves to {@see RelayClient}. A monitor
     *                                 that must not use it never reaches it; see
     *                                 {@see self::transportFor()}.
     * @param  ContentArchive|null  $archive  Filled by the container in every
     *                                        real invocation. Defaulted only so
     *                                        the two-argument `handle()` call
     *                                        sites keep compiling.
     */
    public function handle(ProbeTransport $relay, MetricExtractor $extractor, ?ContentArchive $archive = null): void
    {
        // 0. Name the subject before anything can fail. An error raised below
        //    is otherwise indistinguishable between monitors, and this is the
        //    job that runs tens of thousands of times a day, so "one monitor is
        //    broken" and "the probe path is broken" would look identical in
        //    Sentry. The region goes with it because a failure that only
        //    happens from one vantage point is a different bug.
        //
        //    A no-op without a DSN, which is every local run and the suite.
        Integration::configureScope(function (Scope $scope): void {
            $scope->setTag('monitor_id', (string) $this->monitor->getKey());
            $scope->setTag('monitor_region', $this->region);
        });

        // 1. Probe, through whichever network this monitor is allowed on, and
        //    get the parsed result inline.
        $result = $this->transportFor($relay)->dispatch($this->monitor, $this->region);

        // 2. Read the metrics HERE, the only stage that holds the full body and
        //    now the only stage that extracts at all. `CheckResult::toArray()`
        //    deliberately drops the body (a 1 MB page must never ride Redis) and
        //    `response_body_preview` is capped at 10 KiB, so extracting
        //    downstream would silently cap every metric at that offset
        //    regardless of where its value actually sits in the page.
        $samples = $this->extractSamples($extractor, $result);

        // 3. Decide what happens to the body, for the same reason: this is the
        //    only stage that still has it.
        [$contentHash, $contentHashNormalized] = $this->resolveContentVersion(
            $result,
            $archive ?? app(ContentArchive::class),
        );

        // 4. Hand off to the processing queue for persistence. Only the extracted
        //    scalars and the two resolved hashes travel with the wire payload;
        //    the body stays behind.
        ProcessCheckResult::dispatch(
            $this->monitor->id,
            $this->region,
            $result->toArray(),
            $samples,
            $contentHash,
            $contentHashNormalized,
        )->onQueue('processing');
    }

    /**
     * The transport this monitor's probe is allowed to travel through.
     *
     * The system team's catalog monitors are probed from THIS server through the
     * proxy pool ({@see LocalProbeEngine}); every customer monitor keeps going
     * out through the Cloudflare worker, which is what keeps our own network off
     * the wire as the origin of a request a customer authored.
     *
     * Three properties this shape has and the alternatives do not:
     *
     *  1. The predicate is the `teams.is_system` column, which is not fillable
     *     and has a production precedent in `PlanGate::limits()`. Nothing an
     *     environment or a config file can widen decides it.
     *  2. Anything that is not provably the system team's gets the DEFAULT
     *     transport. A monitor with no resolvable team is a customer monitor as
     *     far as this decision goes, because an unknown owner is not the system
     *     team.
     *  3. The engine is resolved lazily, inside the branch, so a customer check
     *     never constructs the proxy-backed engine at all. Injecting it into
     *     `handle()` would build it (and its pool) on every check of every
     *     monitor. Same reason `handle()` reaches for {@see ContentArchive} the
     *     way it does.
     *
     * {@see LocalProbeEngine::dispatch()} re-asserts the same predicate and
     * throws, so this decision being wrong cannot silently probe a customer's
     * target from here.
     */
    protected function transportFor(ProbeTransport $default): ProbeTransport
    {
        if ($this->monitor->team?->is_system !== true) {
            return $default;
        }

        return app(LocalProbeEngine::class);
    }

    /**
     * Run every configured metric rule against the best body this probe has,
     * and return the raw values keyed by `metric_key`.
     *
     * THIS IS THE ONLY EXTRACTION SITE. The persistence stage used to run a
     * second extraction against `response_body_preview` whenever this one
     * handed it nothing, and the two disagreed in a way nothing surfaced: this
     * method returned early on a null `content` BEFORE it ever looped over the
     * metrics, so a response whose content type the edge filtered recorded
     * nothing at all, including `header` and `http_status` metrics that need no
     * body whatsoever. The downstream fallback could not repair it either,
     * because it only fired on a `null` this method never returned.
     *
     * So the body is resolved here instead of guarded against: the full body
     * when the edge let it through, otherwise the truncated preview, which
     * travels the wire regardless and is strictly better than nothing. Only the
     * body-derived sources (`json_path`, `regex`, `xpath`) notice the
     * difference; `header` and `http_status` read the headers and the status
     * code and are satisfied either way.
     *
     * Narrowed to the extractions that both succeeded and matched their
     * declared type, so the persistence stage stores them without re-reading
     * anything. An empty array therefore means one thing only: nothing was
     * extractable from what this probe returned.
     *
     * @return array<string, string>
     */
    protected function extractSamples(MetricExtractor $extractor, CheckResult $result): array
    {
        $metrics = $this->monitor->metrics()->get();
        if ($metrics->isEmpty()) {
            return [];
        }

        $body = $result->content ?? $result->responseBodyPreview ?? '';
        $samples = [];

        foreach ($metrics as $metric) {
            $extracted = $extractor->extract(
                source: $metric->source,
                extractionPath: $metric->extraction_path ?? '',
                type: $metric->type,
                body: $body,
                // MetricExtractor lower-cases both sides of its header lookup,
                // so the worker's casing needs no normalization first.
                headers: $result->responseHeaders,
                statusCode: $result->statusCode,
            );

            if ($extracted->error !== null || $extracted->value === null || ! $extracted->typeValid) {
                continue;
            }

            $samples[$metric->key] = $extracted->value;
        }

        return $samples;
    }

    /**
     * Decide which archived version this body belongs to, and archive it when it
     * is a version nobody has stored yet.
     *
     * @return array{0: string|null, 1: string|null} The ADDRESS of the version
     *                                               this content resolved to
     *                                               (an earlier body's raw hash
     *                                               on the unchanged path) and
     *                                               this body's own change
     *                                               signal. Both null when
     *                                               nothing was archived.
     */
    protected function resolveContentVersion(CheckResult $result, ContentArchive $archive): array
    {
        $body = $result->content;

        // 1. No body: a TCP probe, a content type the edge filtered out, or a
        //    worker deployment older than the field.
        if ($body === null || $body === '') {
            return [null, null];
        }

        // 2. Re-check the type on this side too. The edge already filters it, so
        //    this is defence in depth against a worker deployment older than that
        //    filter: it is the last gate before untrusted bytes are stored.
        $allowed = ContentTypeAllowList::allows(
            $result->contentType,
            (array) config('content-archive.allowed_content_types'),
        );

        if (! $allowed) {
            return [null, null];
        }

        $claimed = null;

        try {
            $hashes = ContentNormalizer::normalize($body);

            // 3. The breaker comes BEFORE the claim, and the ordering is the whole
            //    point. A claim inserted and then abandoned is unrecoverable: no
            //    job was dispatched, so the archive job's failure hook (the only
            //    cleanup path) never runs, retention never prunes a row that keeps
            //    being touched, and every later identical body is discarded as
            //    already-archived while the download endpoint 404s.
            if ($this->archiveBacklogExceeded()) {
                Log::warning('Monitor content archiving skipped: the archive queue backlog is over its limit.', [
                    'monitor_id' => $this->monitor->getKey(),
                    'region' => $this->region,
                    'queue' => (string) config('content-archive.queue'),
                    'limit' => config('content-archive.queue_backlog_limit'),
                ]);

                return [null, null];
            }

            // 4. CLAIM WON: this content has never been stored for this monitor,
            //    and this process is the one elected to store it.
            if ($this->claimContentVersion($body, $hashes, $result)) {
                $claimed = $hashes;

                $this->dispatchArchiveWrite($body, $hashes);

                return [$hashes->rawHash, $hashes->normalizedHash];
            }

            // 5. CLAIM LOST, or this content was already archived. Re-read on the
            //    normalized key, never on this body's raw hash: a page churning a
            //    per-request token gives every region a different raw hash for the
            //    same content, so a raw-hash lookup would dedupe nothing.
            $existing = $this->archivedVersion($hashes);

            // 6. The row vanished between the claim and the read (a concurrent
            //    failure hook releasing its claim). Fail OPEN to storing rather
            //    than dereference a null; a write with no claim behind it fails
            //    loudly and releases nothing, and the next check re-claims.
            if ($existing === null) {
                $this->dispatchArchiveWrite($body, $hashes);

                return [$hashes->rawHash, $hashes->normalizedHash];
            }

            // 7. Discard the body and point this check at the blob that actually
            //    holds its content, so every region converges on one blob. The
            //    touch takes the FOUND ROW: this body's raw hash addresses
            //    nothing, so touching by it would freeze `last_seen_at` and hand
            //    the retention sweep a live version to delete.
            $archive->touch($existing);

            return [$existing->content_hash, $hashes->normalizedHash];
        } catch (Throwable $e) {
            // The archive is the only thing allowed to fail here: a backlog, a
            // dead mount or a full local disk must cost the content and never the
            // check row, which is written by a downstream job this one has not
            // dispatched yet.
            //
            // A claim of OURS goes with it. Nothing else would release a row whose
            // blob never landed, because the archive job's failure hook only runs
            // for a job that was actually dispatched.
            if ($claimed !== null) {
                $this->releaseClaim($claimed);
            }

            Log::error('Monitor content archive decision failed.', [
                'monitor_id' => $this->monitor->getKey(),
                'region' => $this->region,
                'reason' => $e->getMessage(),
            ]);

            return [null, null];
        }
    }

    /**
     * True when the archive queue holds more pending writes than the configured
     * limit allows.
     *
     * The limit is type-checked instead of compared directly, because `1 > null`
     * is TRUE in PHP: an absent or non-integer config value would otherwise
     * disable archiving the moment the queue went non-empty, while every test
     * against an empty queue stayed green.
     */
    protected function archiveBacklogExceeded(): bool
    {
        $limit = config('content-archive.queue_backlog_limit');

        if (! is_int($limit) || $limit < 1) {
            return false;
        }

        return Queue::size((string) config('content-archive.queue')) > $limit;
    }

    /**
     * Attempt to become the writer for this content, and report whether we won.
     *
     * `insertOrIgnore` is what makes the decision and the mutual exclusion a
     * single operation: it collides on the normalized unique key, so of the two
     * regions that fan out in one tick exactly one is told to write.
     *
     * @return bool True when this process inserted the row, which is the only
     *              licence to write bytes.
     */
    protected function claimContentVersion(string $body, NormalizedContent $hashes, CheckResult $result): bool
    {
        $now = now();

        $inserted = MonitorContentVersion::query()->insertOrIgnore([
            // Explicit, because `insertOrIgnore` bypasses model events so the UUID
            // trait never fires, and `id` is a `uuid` column with no database
            // default under this project's `magic-starter.use_uuids`.
            'id' => (string) Str::orderedUuid(),
            'monitor_id' => $this->monitor->getKey(),
            // The column most easily forgotten and the most damaging to forget:
            // SQLite's `insert or ignore` SWALLOWS a NOT NULL violation, so every
            // claim would read as lost and the dedupe would fail opaquely, while
            // PostgreSQL would throw. Tests and production would disagree.
            'team_id' => $this->monitor->team_id,
            // The ADDRESS of the blob these bytes will occupy.
            'content_hash' => $hashes->rawHash,
            // The change signal, and the key the decision reads.
            'content_hash_normalized' => $hashes->normalizedHash,
            'normalizer_version' => $hashes->normalizerVersion,
            // The RAW decoded length, never the gzipped size on disk; the archive
            // write deliberately leaves this column alone.
            'byte_size' => strlen($body),
            'content_type' => $result->contentType,
            'truncated' => $result->contentTruncated,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inserted > 0;
    }

    /**
     * The version row already holding this monitor's content, or null when none
     * does.
     *
     * Keyed on `(monitor_id, content_hash_normalized, normalizer_version)`, the
     * unique index the claim collides on, and on nothing else. Keying on the
     * previous CHECK row instead would chain per region and give a five-region
     * monitor five copies of every version.
     */
    protected function archivedVersion(NormalizedContent $hashes): ?MonitorContentVersion
    {
        return MonitorContentVersion::query()
            ->where('monitor_id', $this->monitor->getKey())
            ->where('content_hash_normalized', $hashes->normalizedHash)
            ->where('normalizer_version', $hashes->normalizerVersion)
            ->first();
    }

    /**
     * Spool the gzipped body to local disk and hand the archive job its path.
     *
     * @throws RuntimeException When the body cannot be compressed or spooled. The
     *                          caller releases the claim on it; a claim with no
     *                          job behind it is what makes later identical bodies
     *                          look archived forever.
     */
    protected function dispatchArchiveWrite(string $body, NormalizedContent $hashes): void
    {
        $compressed = gzencode($body);
        $spoolPath = tempnam(sys_get_temp_dir(), self::SPOOL_PREFIX);

        if ($compressed === false || $spoolPath === false) {
            throw new RuntimeException('Failed to compress or spool monitor content for the archive.');
        }

        if (file_put_contents($spoolPath, $compressed) === false) {
            unlink($spoolPath);

            throw new RuntimeException('Failed to write the monitor content spool at ['.$spoolPath.'].');
        }

        // The PATH travels, never the bytes. A 1 MB page on the queue, times every
        // check of every monitor, is a Redis instance that evicts its own jobs.
        //
        // The dispatch is guarded because it is the one step whose failure leaves an
        // orphan nothing reclaims: the archive job deletes its own spool in a
        // `finally`, but a push that never reached the queue has no job to run that
        // `finally`, and there is no spool reaper. Unlink here, then rethrow so the
        // caller's handler still releases the claim this spool belonged to.
        try {
            ArchiveContent::dispatch($this->monitor, $spoolPath, $hashes);
        } catch (Throwable $e) {
            if (is_file($spoolPath)) {
                unlink($spoolPath);
            }

            throw $e;
        }
    }

    /**
     * Delete a claim this process inserted but could not hand to the archive job.
     *
     * The same address triple the archive job's failure hook deletes on, so the
     * two cleanup paths cannot disagree about what a claim is.
     */
    protected function releaseClaim(NormalizedContent $hashes): void
    {
        MonitorContentVersion::query()
            ->where('monitor_id', $this->monitor->getKey())
            ->where('content_hash', $hashes->rawHash)
            ->where('normalizer_version', $hashes->normalizerVersion)
            ->delete();
    }
}
