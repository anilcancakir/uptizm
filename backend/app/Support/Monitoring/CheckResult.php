<?php

namespace App\Support\Monitoring;

use App\Enums\MonitorStatus;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\Monitoring\LocalProbeEngine;
use App\Services\Proxy\ProxyPool;
use DateTimeImmutable;

/**
 * Immutable value object describing the outcome of a single probe
 * executed by a Cloudflare relay worker.
 *
 * This is the wire contract between the worker's `/run` response and the
 * job that persists check results. Field names must stay aligned with
 * `workers/regional-checker/src/index.ts`. `probeRunId` is the idempotency
 * key: it lets the processing job de-duplicate a worker payload that is
 * delivered more than once.
 */
readonly class CheckResult
{
    /**
     * The `monitor_content_versions.content_type` column width; a longer header
     * is cut to it as it enters (see {@see fromWorkerPayload()}).
     */
    protected const int CONTENT_TYPE_MAX_LENGTH = 128;

    /**
     * The `monitor_checks.exit_via` column width.
     */
    protected const int MAX_EXIT_VIA_LENGTH = 64;

    /**
     * @param  array<string, string>  $responseHeaders
     */
    public function __construct(
        public string $monitorId,
        public string $region,
        public DateTimeImmutable $checkedAt,
        public MonitorStatus $status,
        public ?int $statusCode,
        public ?int $responseMs,
        public ?string $errorMessage,
        public int $timingDnsMs,
        public int $timingConnectMs,
        public int $timingTlsMs,
        public int $timingTtfbMs,
        public int $timingDownloadMs,
        public array $responseHeaders,
        public ?string $responseBodyPreview,
        public string $probeRunId,

        /**
         * The Cloudflare colo the probe ran from, e.g. `FRA`.
         *
         * This is the only EVIDENCE of where a check happened. `region` is an
         * echo of what the caller asked for, so without the colo a mis-mapped
         * `locationHint` would produce identical probes under different region
         * labels and nothing would catch it.
         *
         * Nullable because an older worker deployment does not send it, and a
         * payload replayed from before this field existed must still parse.
         */
        public ?string $colo = null,

        /**
         * The proxy exit a locally-produced check egressed through, as
         * `host:port`.
         *
         * Extends the {@see $colo} reasoning rather than restating it: a
         * proxy-derived `region` is an echo of what {@see ProxyPool} was asked
         * for, not evidence of what actually carried the request, so this
         * field is what makes one blocked or misbehaving exit distinguishable
         * from every other reading in the same region. `colo` cannot hold it
         * (`string(8)`, sized for a three-letter IATA code), so it is a
         * separate column rather than a repurposed one.
         *
         * Null on every worker-produced check, which has a colo instead and no
         * proxy exit to report; populated only by {@see LocalProbeEngine}.
         * Deliberately never surfaced through a tenant-facing resource or
         * view: it is operator evidence, and publishing a third-party exit
         * invites the exact block-one-and-move-on dynamic the design refuses.
         */
        public ?string $exitVia = null,

        /**
         * True when the EDGE refused to run the probe, rather than the target
         * failing it.
         *
         * `connect()` rejects a raw TCP connection to any host Cloudflare serves
         * over HTTP, which is every proxied hostname. That says nothing about the
         * customer's service, so it must not be counted as a failed check:
         * counting it opens an incident and pages someone for a target that is
         * up. It must not count as a SUCCESS either, because resetting the
         * failure streak would mask a real outage underneath.
         */
        public bool $probeRefused = false,

        /**
         * The full DECODED response body, up to `content-archive.max_bytes`.
         *
         * The worker reads the body once, which is what makes the runtime
         * decompress it, so this is plain content whether the origin served
         * gzip, brotli or nothing. That decoding is required for the archive
         * hash to be stable: the same page compressed twice is not the same
         * bytes.
         *
         * Null for a TCP probe, for a response whose content type falls outside
         * `content-archive.allowed_content_types` (the edge filters it, so a
         * disallowed body never crosses the wire), and for any payload from a
         * worker deployment older than this field.
         *
         * It is deliberately ABSENT from {@see toArray()}; see that docblock.
         */
        public ?string $content = null,

        /**
         * The raw `content-type` response header, truncated to 128 characters
         * in {@see fromWorkerPayload()}.
         *
         * Carried even when {@see $content} was filtered out, because knowing
         * WHICH type was rejected is what makes a missing archive explainable.
         */
        public ?string $contentType = null,

        /**
         * True when the body reached `content-archive.max_bytes` and the
         * remainder was discarded at the edge, so {@see $content} is a prefix of
         * what the target actually served rather than the whole of it.
         */
        public bool $contentTruncated = false,

        /**
         * The edge's assertion report, or null when the monitor asserted nothing.
         *
         * Shaped `['passed' => bool, 'results' => list<array>]`, mirroring the
         * worker's `CheckResultPayload.assertions`. ONE nullable field rather than
         * a `passed` boolean beside a `results` array, and the nesting is
         * load-bearing for the same reason it is on the wire: two nullable fields
         * make four states representable and two of them are contradictions, a
         * verdict with no results and results with no verdict, each landing as one
         * populated column beside one NULL with nothing to say which lied.
         *
         * `passed` is decided at the EDGE and is never re-derived here.
         * All-rules-must-pass and skipped-is-not-a-failure are implemented in
         * `workers/regional-checker/src/assertions.ts`; re-deriving the verdict
         * from `results` in PHP would be a second implementation of both rules in
         * another language, and the two would drift.
         *
         * This one DOES travel through {@see toArray()} and therefore through the
         * Redis `processing` queue, unlike {@see $content}. That is affordable
         * because BOTH halves of an outcome are bounded, and it takes both: the
         * edge cuts every recorded `observed` value to 256 characters, and
         * {@see AssertionRuleSet} refuses at save time a rule over
         * `RULE_MAX_BYTES` (4096) or a set over `RULES_MAX_COUNT` (20). So a
         * report is at worst 20 × (4 KB of echoed rule + 1 KB of observed
         * excerpt), which is ~100 KB, against the megabyte a page body would be.
         *
         * The observed cap alone would not do it, and reading it as sufficient was
         * a real error in an earlier version of this docblock: every outcome also
         * echoes the RULE verbatim, so an uncapped `body contains` needle is
         * whatever an operator pasted, on every check, from every region. That the
         * rule bound lives at save time rather than at the edge is safe for one
         * documented reason: the staff Filament panel is the only writer, no API
         * `FormRequest` names `assertion_rules`, and widening that surface has to
         * carry the screen with it (see {@see AssertionRuleSet}'s own docblock).
         *
         * @var array{passed: bool, results: list<array<string, mixed>>}|null
         */
        public ?array $assertions = null,
    ) {}

    /**
     * A copy of this result with every target-chosen text field scrubbed of the
     * operator's own credential.
     *
     * The class is `readonly`, so this reconstructs rather than mutates. It
     * reconstructs with NAMED arguments beside the promoted property list, the
     * same discipline {@see fromWorkerPayload()} carries, because the
     * alternative (a hand-rolled `new CheckResult(...)` at the call site) is
     * where a positional argument silently lands in the wrong slot.
     *
     * Five fields carry text the monitored TARGET chose and can therefore echo
     * a credential back: {@see $errorMessage}, {@see $responseHeaders},
     * {@see $responseBodyPreview}, {@see $content} and {@see $assertions},
     * whose per-rule `observed` excerpts are up to 256 characters of the same
     * target-chosen text. Every other property is our own telemetry (timings,
     * status, colo, probe run id), and redacting a credential out of an integer
     * is not a thing.
     *
     * `assertions` is scrubbed even though the analyze path cannot produce one
     * today (its transient monitor sets no assertion rules), because this is a
     * general method on a shared value object and the next caller will assume
     * it scrubbed everything. Its `passed` verdict is carried through untouched:
     * it is a boolean decided at the edge, and re-deriving it is what
     * {@see $assertions} forbids.
     *
     * THE RESIDUAL, stated because the design accepts it rather than solves it:
     * a property added to the constructor and not added here silently resets to
     * its DEFAULT instead of failing, and only the seven defaulted properties
     * can do that (a required one is a fatal, which is the loud half). There is
     * no reflection-driven copy that would catch it without giving up the named
     * arguments that make the mapping readable, so the mitigation is that this
     * method sits directly beneath the property list it must stay in step with.
     */
    public function withRedacted(CredentialRedactor $redactor): self
    {
        return new self(
            monitorId: $this->monitorId,
            region: $this->region,
            checkedAt: $this->checkedAt,
            status: $this->status,
            statusCode: $this->statusCode,
            responseMs: $this->responseMs,
            errorMessage: $redactor->redact($this->errorMessage),
            timingDnsMs: $this->timingDnsMs,
            timingConnectMs: $this->timingConnectMs,
            timingTlsMs: $this->timingTlsMs,
            timingTtfbMs: $this->timingTtfbMs,
            timingDownloadMs: $this->timingDownloadMs,
            responseHeaders: $redactor->redactMap($this->responseHeaders),
            responseBodyPreview: $redactor->redact($this->responseBodyPreview),
            probeRunId: $this->probeRunId,
            colo: $this->colo,
            exitVia: $this->exitVia,
            probeRefused: $this->probeRefused,
            content: $redactor->redact($this->content),
            contentType: $this->contentType,
            contentTruncated: $this->contentTruncated,
            assertions: $this->redactedAssertions($redactor),
        );
    }

    /**
     * The assertion report with its outcome rows scrubbed, or null.
     *
     * `array_values()` because {@see $assertions} declares `results` a LIST and
     * {@see CredentialRedactor::redactMap()} preserves keys: a report whose
     * list-ness was lost would still serialize, and would then decode as an
     * object through {@see assertionsFrom()}'s `array_is_list()` check and be
     * discarded as a shape this build does not understand.
     *
     * @return array{passed: bool, results: list<array<string, mixed>>}|null
     */
    private function redactedAssertions(CredentialRedactor $redactor): ?array
    {
        if ($this->assertions === null) {
            return null;
        }

        return [
            'passed' => $this->assertions['passed'],
            'results' => array_values($redactor->redactMap($this->assertions['results'])),
        ];
    }

    /**
     * Read the assertion report out of a worker payload, or null.
     *
     * The shape is validated HERE, at the boundary where untrusted data enters,
     * for the same reason `content_type` is cut to its column width here: the row
     * write happens inside {@see CheckPersistenceService}'s transaction, so a
     * throw down there loses the whole check row, and monitoring must never
     * degrade to protect a secondary field. A payload whose `assertions` is
     * present but not a `{passed, results}` pair is therefore treated as no
     * report at all rather than half-stored.
     *
     * Absent-tolerant like `colo` and `exit_via`: a payload replayed from before
     * this field existed must still parse.
     *
     * @param  array<string, mixed>  $payload
     * @return array{passed: bool, results: list<array<string, mixed>>}|null
     */
    private static function assertionsFrom(array $payload): ?array
    {
        $assertions = $payload['assertions'] ?? null;

        // `passed` must be a REAL boolean, not merely present. A `(bool)` cast over
        // untrusted input reads the string "false" as true and null as false, so a
        // payload from a build that shipped the field wrong would persist a verdict
        // nobody measured, in whichever direction the cast happened to land. `results`
        // must be a list for the same reason: an object there means the edge sent a
        // shape this build does not understand, and a report is only usable whole.
        if (! is_array($assertions)
            || ! is_bool($assertions['passed'] ?? null)
            || ! is_array($assertions['results'] ?? null)
            || ! array_is_list($assertions['results'])) {
            return null;
        }

        return [
            'passed' => $assertions['passed'],
            'results' => $assertions['results'],
        ];
    }

    /**
     * Build a CheckResult from the JSON payload returned by the worker.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromWorkerPayload(array $payload): self
    {
        return new self(
            monitorId: (string) $payload['monitor_id'],
            region: (string) $payload['region'],
            checkedAt: new DateTimeImmutable((string) $payload['checked_at']),
            status: MonitorStatus::from((string) $payload['status']),
            statusCode: isset($payload['status_code']) ? (int) $payload['status_code'] : null,
            responseMs: isset($payload['response_ms']) ? (int) $payload['response_ms'] : null,
            errorMessage: $payload['error_message'] ?? null,
            timingDnsMs: (int) ($payload['timing']['dns_ms'] ?? 0),
            timingConnectMs: (int) ($payload['timing']['connect_ms'] ?? 0),
            timingTlsMs: (int) ($payload['timing']['tls_ms'] ?? 0),
            timingTtfbMs: (int) ($payload['timing']['ttfb_ms'] ?? 0),
            timingDownloadMs: (int) ($payload['timing']['download_ms'] ?? 0),
            responseHeaders: (array) ($payload['response_headers'] ?? []),
            responseBodyPreview: $payload['response_body_preview'] ?? null,
            probeRunId: (string) $payload['probe_run_id'],
            colo: isset($payload['colo']) && $payload['colo'] !== ''
                ? (string) $payload['colo']
                : null,
            // Absent-tolerant like `colo`: a worker payload never carries this
            // key, and a payload replayed from before this field existed must
            // still parse.
            // Cut to the column width at the same boundary and for the same reason as
            // `content_type` below: PostgreSQL throws on an over-long value rather than
            // trimming it, and a throw here happens INSIDE the persist transaction and
            // loses the whole check row. Webshare lists IPs so 64 is generous today, but
            // a hostname-based provider endpoint is not bounded by anything we control.
            exitVia: isset($payload['exit_via']) && $payload['exit_via'] !== ''
                ? mb_substr((string) $payload['exit_via'], 0, self::MAX_EXIT_VIA_LENGTH)
                : null,
            probeRefused: (bool) ($payload['probe_refused'] ?? false),
            content: isset($payload['content']) ? (string) $payload['content'] : null,
            // Cut to 128 characters HERE, at the boundary where untrusted data
            // enters: this is a raw header chosen by the monitored target and it
            // lands in a `string(128)` column, where PostgreSQL throws on an
            // over-long value rather than trimming it. The archive claim runs
            // inside `PerformMonitorCheck` before `ProcessCheckResult` is
            // dispatched, so that throw would take the whole check down and lose
            // the telemetry. The archive degrades first; monitoring never does.
            contentType: isset($payload['content_type']) && $payload['content_type'] !== ''
                ? mb_substr((string) $payload['content_type'], 0, self::CONTENT_TYPE_MAX_LENGTH)
                : null,
            contentTruncated: (bool) ($payload['content_truncated'] ?? false),
            assertions: self::assertionsFrom($payload),
        );
    }

    /**
     * Serialize back to the worker wire shape, MINUS the response body.
     *
     * The output round-trips through {@see fromWorkerPayload()}; the
     * processing job uses it to hand the parsed result forward without
     * re-deriving the individual fields.
     *
     * `content` is deliberately omitted while `content_type` and
     * `content_truncated` are present, and that asymmetry must not be "fixed".
     * `PerformMonitorCheck` dispatches this array onto the Redis `processing`
     * queue, so including the body would push every HTTP check's full page (up
     * to 1 MB) into Redis. Queue keys carry no TTL, so under `volatile-lru` the
     * eviction victims are {@see CheckPersistenceService}'s
     * locks, and losing one races `consecutive_fails` into a duplicate incident.
     * The body reaches the archive from the body-owning stage instead, never
     * through a queue payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'monitor_id' => $this->monitorId,
            'region' => $this->region,
            'checked_at' => $this->checkedAt->format(DateTimeImmutable::ATOM),
            'status' => $this->status->value,
            'status_code' => $this->statusCode,
            'response_ms' => $this->responseMs,
            'error_message' => $this->errorMessage,
            'timing' => [
                'dns_ms' => $this->timingDnsMs,
                'connect_ms' => $this->timingConnectMs,
                'tls_ms' => $this->timingTlsMs,
                'ttfb_ms' => $this->timingTtfbMs,
                'download_ms' => $this->timingDownloadMs,
            ],
            'response_headers' => $this->responseHeaders,
            'response_body_preview' => $this->responseBodyPreview,
            'probe_run_id' => $this->probeRunId,
            'colo' => $this->colo,
            'exit_via' => $this->exitVia,
            'probe_refused' => $this->probeRefused,
            'content_type' => $this->contentType,
            'content_truncated' => $this->contentTruncated,
            // Present, unlike `content` above, and see {@see $assertions} for why
            // the queue can afford it: the edge bounds each recorded `observed`
            // value and `AssertionRuleSet` bounds the rules being echoed beside
            // them, which together put a report in the tens of kilobytes.
            'assertions' => $this->assertions,
        ];
    }
}
