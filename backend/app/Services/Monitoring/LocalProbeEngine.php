<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Jobs\AlarmDarkProbeRegions;
use App\Models\Monitor;
use App\Models\ProbeRegionHealth;
use App\Models\Proxy;
use App\Services\Proxy\ProxyPool;
use App\Support\Monitoring\CheckResult;
use App\Support\Proxy\ProxyRegions;
use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Probes a monitor from THIS server, egressing through the regional proxy pool
 * instead of the Cloudflare worker.
 *
 * The catalog monitors owned by the internal system team (`SystemTeam`, in
 * app/Support/Services) are the only monitors allowed on this path.
 * `PerformMonitorCheck::transportFor()` makes that routing decision; this class
 * re-asserts it, and the duplication is the point.
 *
 * ## Why the guard is here as well as at the caller
 *
 * The Cloudflare worker's isolation from our own network is STRUCTURAL: it runs
 * somewhere else. This engine has none of that, and the proxy is the only thing
 * that re-establishes the boundary. A customer monitor probed here would put
 * this VPS on the wire as the origin of a request the customer authored, and the
 * box also runs the app, PostgreSQL, Redis and sibling sites.
 *
 * So the decision and the invariant deliberately live in different classes. A
 * caller that resolves this engine directly, a queue worker handed the wrong
 * payload, or an edit to the routing predicate cannot move a customer monitor
 * onto our egress unless the guard below is also deleted. The guard sits in
 * {@see dispatch()}, above the {@see probe()} seam, so a subclass replacing the
 * probe (a test double, or a future variant) cannot skip it either.
 *
 * The predicate is the `teams.is_system` column and nothing else: no config
 * flag, no allowlist, nothing an environment can widen.
 *
 * ## One wire shape, two transports
 *
 * The reading is emitted as the worker's own payload and parsed by
 * {@see CheckResult::fromWorkerPayload()}, so both transports travel through one
 * parser and land in one `monitor_checks` table. That is also where the raw
 * `content-type` gets cut to its column width, at the boundary where a header the
 * TARGET chose enters. The status rule is reproduced from
 * `workers/regional-checker/src/regional-probe.ts:489-497` and is THREE-way: an
 * exact match against `expected_status_code` is up, ANY other 2xx or 3xx is
 * degraded, everything else is down. A 200 against an expected 204 is therefore
 * degraded, not down; collapsing that to two values pages a responder for a
 * service that answered.
 *
 * Redirects are not followed (`allow_redirects => false`), because Guzzle follows
 * them by default and `resources/legal/bot.en.md:24-28` publishes that this probe
 * follows no links. A 301 is recorded as a 301.
 *
 * ## THE BODY IS NEVER DOWNLOADED, AND A SUCCESS ARRIVES AS AN EXCEPTION
 *
 * {@see attempt()} captures the status and the headers inside an `on_headers`
 * callback and then throws {@see self::BODY_ABORTED} out of it, which aborts the
 * rest of the transfer. Guzzle catches that inside `CurlFactory`, returns -1 to
 * curl, and curl reports errno 23 (`CURLE_WRITE_ERROR`); because a 2xx yields no
 * `Response::toException()`, Laravel then wraps it as an
 * `Illuminate\Http\Client\ConnectionException`. Measured on this machine, both
 * against example.com and against the loopback server in
 * `tests/Feature/Monitoring/LocalProbeEngineTest.php`: outer class
 * `ConnectionException`, previous `GuzzleHttp\Exception\RequestException`, errno
 * 23, message "An error was encountered during the on_headers event", with the
 * callback's capture holding status 200 and the content type.
 *
 * SO THE DISCRIMINATOR IS NOT THE ERRNO AND NOT THE EXCEPTION CLASS: it is
 * whether the callback captured anything. A populated capture means the probe
 * reached the target and the exception is this engine's own doing. An empty
 * capture means the target was never reached. The check therefore comes FIRST in
 * the catch, above any failure classification. Getting that order backwards sends
 * every successful catalog probe into a retry ladder and eventually writes a
 * fabricated `down` on eight public pages.
 *
 * Not downloading the body is a cost decision, not a micro-optimisation: nothing
 * consumes it for these monitors (`assertion_rules` is null and `ai_mode` is off
 * on all eight), the measured payloads are ~590 KB per probe against metered
 * proxy bandwidth, and pulling 590 KB to store 10 KB nobody opens is load we also
 * impose on the target we are probing. `response_body_preview`, `content` and
 * `content_truncated` are consequently null, null and false, all already legal on
 * the wire (see {@see CheckResult::$content}).
 *
 * That is also why `App\Support\Monitoring\ContentTypeAllowList` is NOT consulted
 * here, and its absence is deliberate rather than an omission. At the edge the
 * allowlist decides a body CEILING (`regional-probe.ts:531-537`: the archive limit
 * for an archivable type, zero for anything else). This engine's ceiling is zero
 * for every type, so the matcher has no decision left to make, and calling it to
 * discard the answer would read like a live gate to the next person here. The
 * `content_type` header is still carried verbatim, which is what keeps a missing
 * archive explainable, and `PerformMonitorCheck::resolveContentVersion()` still
 * runs the real matcher before anything is stored.
 *
 * TWO MECHANISMS ARE FORBIDDEN HERE because both look right and are not.
 * `CURLOPT_MAXFILESIZE` aborts with `CURLE_FILESIZE_EXCEEDED` and yields no
 * response at all. And Guzzle's `stream` request option is FATAL: with
 * `allow_url_fopen` on, `Utils::chooseHandler()` wraps the STREAM handler, which
 * ignores every curl option by its own deprecation notice, so
 * `CURLOPT_PROXYUSERPWD` is dropped silently. The live consequence is that the
 * proxy answers 407, the never-rotate-on-an-HTTP-answer rule records that 407 as
 * the TARGET's answer, and all eight catalog monitors write fabricated `down`
 * rows while every `Http::fake` test stays green.
 *
 * ## What the timing fields mean here, which is not what they mean at the edge
 *
 * The phases come from curl's own cumulative timestamps via `handlerStats()`
 * (see {@see timingFrom()}), so they are measurements rather than estimates. But
 * WITH A PROXY IN THE PATH `connect_time` is the hop to the PROXY, not to the
 * target, and curl exposes no separate stat for the tunnel-to-target phase: for a
 * TLS target the CONNECT round trip is folded into the `appconnect` window this
 * class reports as `tls_ms`. So `connect_ms` and `tls_ms` mean something
 * different from the worker's, and treating them as the same measurement would be
 * a fabrication. `response_ms` is curl's `total_time`, which for this engine ends
 * at the aborted body rather than at the last byte, so it is a
 * time-to-first-byte-shaped number and reads faster than the worker's for the
 * same target. `handlerStats()` is empty when no transfer happened (a stubbed
 * response), and the engine then reports zeros and a null `response_ms` rather
 * than inventing a number.
 *
 * ## WHOSE FAILURE WAS IT: the attribution ladder
 *
 * An empty capture means the target was never reached, and the question that
 * decides everything after it is whether OUR OWN EXIT failed or the target did.
 * curl cannot answer it: a dead proxy and a dead target both report errno 7
 * (`CURLE_COULDNT_CONNECT`), and only the ` via <ip>` fragment of curl's prose
 * differs, which is not a discriminator. So the answer is assembled from three
 * things instead.
 *
 * 1. Two errnos name the proxy outright and nothing else: 5
 *    (`CURLE_COULDNT_RESOLVE_PROXY`) and 97 (`CURLE_PROXY`). See
 *    {@see blamesTheProxy()}, which reads them through `getPrevious()`: at the
 *    Laravel layer EVERY transport failure arrives as an
 *    `Illuminate\Http\Client\ConnectionException`, an empty subclass of
 *    `HttpClientException` with no `getHandlerContext()` of its own, and the
 *    Guzzle `RequestException` underneath it is what carries the handler
 *    context. Guzzle's `connectionErrors` list excludes both 5 and 97
 *    (`CurlFactory.php:1082-1088`), so the exception CLASS is evidence of
 *    nothing here either.
 * 2. A 407 is the one HTTP status on this path that cannot have come from the
 *    target: only a proxy sends `Proxy Authentication Required`. The
 *    never-rotate-on-an-HTTP-answer rule cannot be what catches it precisely
 *    BECAUSE it is an HTTP answer, so the ladder handles it explicitly.
 *    Unhandled it publishes a fabricated `down` on eight public pages the first
 *    time a provider rotates our credentials.
 * 3. Everything else (errno 7, a timeout, anything unrecognised) is AMBIGUOUS,
 *    and the only way to resolve it is to ask a second exit in the same region.
 *
 * From that, {@see runAttemptLadder()} produces exactly three outcomes:
 *
 * - A READING, when an exit answered, or when every attempt made failed in a way
 *   we could not pin on ourselves (a `down` two exits of the region agree on).
 *   Note "two exits", NOT two independent ones: a region has at most one source
 *   and every exit carries its `proxy_source_id`, so both attempts share one
 *   vendor and their agreement is one vendor's word twice. Cross-vendor
 *   independence would have to come from the cross-region quorum, and only if the
 *   regions are sourced from different providers.
 * - A REFUSAL (`probeRefused`), when an attempt failed on OUR side and none
 *   answered, or when the region had no exit to try at all. It carries
 *   `status = down` on the wire for a reader that predates the flag, exactly as
 *   `regional-probe.ts:292` does, and routes into
 *   `CheckPersistenceService::persist()`'s existing early return: no check row,
 *   no `consecutive_fails`, no incident, nobody paged, and `last_probe_error`
 *   set for the operator. There is deliberately no second escape hatch.
 * - An EXCEPTION, only for a monitor this engine must not probe at all.
 *
 * THE ASYMMETRY IS THE POINT. A verdict requires that we could not blame
 * ourselves; a refusal is what happens whenever we could. Backwards, uptizm
 * opens an incident against github.com because a proxy we rent stopped
 * answering, which is the one failure this whole transport is judged on. The
 * mirror hazard is real too and is why an ambiguous failure still yields a
 * verdict: refusing whenever a region is thin would mean a genuinely down target
 * in a one-exit region never opens an incident at all.
 *
 * Penalties follow the same evidence. An exit that named itself is penalised
 * immediately; an exit whose failure was ambiguous is penalised only once a LATER
 * exit answers, which is what turns the ambiguity into evidence; and when the
 * verdict lands on the target, NO exit is penalised, because the same reasoning
 * that convicts the target exonerates them, and draining a region's pool during a
 * real outage of a popular target is what the alternative costs. An HTTP status
 * never penalises anything (a 403, a 429 and a 5xx are all the target's answer),
 * with 407 as the single stated exception above.
 *
 * `colo` stays null because this engine has no Cloudflare colo to report. The
 * exit identity it does have instead is recorded on `exit_via` ({@see readingFrom()}),
 * never smuggled into `colo`'s eight-character column: see
 * {@see CheckResult::$exitVia} for why it is a separate field.
 */
class LocalProbeEngine implements ProbeTransport
{
    /**
     * The sentinel thrown out of the `on_headers` callback to abort the body.
     *
     * A message on a plain exception rather than a dedicated class, because
     * nothing ever catches it BY TYPE: Guzzle catches it as a Throwable, and the
     * engine's discriminator on the way back out is the capture, not the
     * exception. A type nobody matches on would be a class for its own sake.
     */
    protected const string BODY_ABORTED = 'The probe aborted the response body at the headers.';

    /**
     * The curl error codes that name OUR OWN EXIT and cannot mean anything else.
     *
     * 5 is `CURLE_COULDNT_RESOLVE_PROXY` (the proxy's own hostname would not
     * resolve) and 97 is `CURLE_PROXY` (the proxy layer itself failed). Both name
     * the proxy in their own definition, which is the bar for membership here: a
     * code added on a guess converts a real outage into a refusal and stops paging
     * anyone.
     *
     * Deliberately NOT here: 7 (`CURLE_COULDNT_CONNECT`). It is usually about the
     * proxy, because every probe carries an explicit exit (`egressOptions()` sets
     * `proxy` unconditionally and `dispatch()` refuses an empty-pool region before
     * a request is built), so the proxy is the only host curl opens a TCP
     * connection to. But libcurl OVERLOADED it: from 8.20.0 a non-2xx CONNECT
     * reply also returns 7 (`lib/cf-h1-proxy.c`, verified at the shipped tags,
     * `curl-8_19_0` returns `CURLE_RECV_ERROR` and `curl-8_20_0` and
     * `curl-8_21_0` return `CURLE_COULDNT_CONNECT`). A blanket 7 would therefore
     * read a proxy's `response 502`, which is the ORIGIN refusing, as our own
     * failure on any modern libcurl. So 7 is decided in {@see self::blamesTheProxy()}
     * by its MESSAGE first and only defaults to ours when no tunnel is named.
     *
     * The version trap is not hypothetical here. This machine's PHP links libcurl
     * 8.18.0 (`curl_version()`, and note the `curl` CLI is a different build at
     * 8.7.1, so measuring with the CLI does not measure what the engine uses), and
     * the plan's own risk register requires moving past 8.20.0 to escape a
     * stale-proxy-password CVE. The classifier has to be right on both sides of
     * that boundary without a runtime version branch.
     *
     * Also re-derive all of this before changing the proxy SCHEME. Under
     * `socks5://` curl routes a SOCKS-layer failure about the ORIGIN into
     * `CURLE_PROXY` (`lib/socks.c`), which is errno 97 and already in this list, so
     * a scheme change would silence real outages through a code nobody touched.
     *
     * @var list<int>
     */
    protected const array PROXY_FAULT_ERRNOS = [
        5,
        97,
    ];

    /**
     * The errnos a failed CONNECT tunnel can arrive as, across curl generations.
     *
     * 56 (`CURLE_RECV_ERROR`) up to libcurl 8.19.0 and 7 (`CURLE_COULDNT_CONNECT`)
     * from 8.20.0, for the same failure and the same message. Neither belongs in
     * {@see self::PROXY_FAULT_ERRNOS}: 56 also covers a target dropping a
     * connection mid-response, and 7 also covers our own exit's port being closed.
     * The errno only says "a tunnel failure could be what this is"; the message
     * decides what it actually was.
     *
     * 28 is deliberately absent. `Proxy CONNECT aborted due to timeout` shares a
     * signature below but a timeout inside CONNECT may be the proxy waiting on the
     * TARGET, so it has to stay ambiguous.
     *
     * @var list<int>
     */
    protected const array TUNNEL_FAILURE_ERRNOS = [
        self::CONNECT_FAILED_ERRNO,
        56,
    ];

    /**
     * `CURLE_COULDNT_CONNECT`, which on this path means we never reached our own
     * exit's port, EXCEPT when its message names a tunnel reply (libcurl >= 8.20.0).
     */
    protected const int CONNECT_FAILED_ERRNO = 7;

    /**
     * The prefix curl writes when the proxy ANSWERED the CONNECT with a status.
     *
     * Its presence means a tunnel reply exists, which is what lets the response
     * code inside it decide the blame rather than the errno. Matching this prefix
     * as a verdict on its own is the mistake to avoid: curl writes it for EVERY
     * non-2xx reply, so it also covers the origin refusing.
     */
    protected const string TUNNEL_REPLY_MARKER = 'CONNECT tunnel failed, response ';

    /**
     * The CONNECT reply codes that can only be the proxy speaking about ITSELF.
     *
     * 407 `Proxy Authentication Required` is the whole list, because inside a
     * CONNECT reply the proxy has no origin HTTP exchange to relay: 502/503/504 is
     * it reporting that it could not reach the ORIGIN, which is a real outage and
     * must stay a `down`. Measured on curl 8.7.1 against a local CONNECT proxy, the
     * two cases differ by exactly one number: `response 407` versus `response 502`.
     *
     * A known gap, recorded rather than guessed at: a rented pool answering 403 or
     * 429 to CONNECT (a concurrency cap, a lapsed bill) is also the proxy speaking
     * about itself, and stays ambiguous here. Adding a code needs the same evidence
     * 407 has, because every addition trades a fabricated outage for a silenced one.
     *
     * @var list<string>
     */
    protected const array PROXY_OWN_REPLY_CODES = [
        '407',
    ];

    /**
     * Messages that name a tunnel our exit never completed, with no reply to read.
     *
     * `Proxy CONNECT aborted` is the proxy closing the socket part-way through its
     * CONNECT reply (curl `lib/cf-h1-proxy.c`). It carries no response code, so
     * {@see self::PROXY_OWN_REPLY_CODES} cannot judge it, and it is unambiguous for
     * a different reason: completing the handshake is the proxy's own protocol
     * obligation and no target byte is involved. Without it, a provider dying inside
     * the handshake reads as ambiguous and publishes a fabricated outage on eight
     * pages about a target nothing ever reached.
     *
     * `Received HTTP code 407 from proxy after CONNECT` is curl's PRE-8.x wording
     * for the 407 case (verified present at `curl-7_86_0:lib/http_proxy.c` and
     * absent from `lib/cf-h1-proxy.c` and `lib/http_proxy.c` at `curl-8_0_0`,
     * `curl-8_10_0`, `curl-8_18_0` and `curl-8_21_0`; the cfilter rewrite replaced
     * it in 7.87.0). Kept for a deploy on an old distro libcurl, and labelled 7.x
     * rather than "older 8.x", which is what an earlier revision of this docblock
     * wrongly claimed.
     *
     * @var list<string>
     */
    protected const array TUNNEL_ABORTED_SIGNATURES = [
        'Proxy CONNECT aborted',
        'HTTP code 407 from proxy after CONNECT',
    ];

    /**
     * The only HTTP status on this path that is not the target's answer.
     *
     * A proxy answers 407 when it will not carry the request; the target never
     * sees it. Treated exactly like {@see PROXY_FAULT_ERRNOS} in the ladder, and
     * never recorded as a reading.
     */
    protected const int PROXY_AUTH_REQUIRED = 407;

    public function __construct(
        protected ProxyPool $pool,
    ) {}

    /**
     * Execute one probe for a (monitor, region) pair the system team owns.
     *
     * @throws RuntimeException When the monitor is not the system team's, or
     *                          when the probe cannot run for this monitor.
     */
    public function dispatch(Monitor $monitor, string $region): CheckResult
    {
        // 1. The invariant, before anything touches the network.
        $this->assertSystemOwned($monitor);

        // 2. The probe proper, overridable so the guard above cannot be bypassed
        //    by a subclass that only replaces the network work.
        $result = $this->probe($monitor, $region);

        // 3. Region health is recorded HERE, after `probe()` has fully decided
        //    refused vs. a reading, never at entry: at entry that distinction
        //    does not exist yet, and every attempt would look identical
        //    regardless of what it produced. A thrown exception above (a TCP
        //    monitor, a non-system monitor) never reaches this line, which is
        //    correct: neither is evidence about a region's exit pool.
        $this->recordRegionHealth($result);

        return $result;
    }

    /**
     * Run the HTTP probe through the region's pool, or refuse.
     *
     * A region this engine cannot egress from produces NO VERDICT rather than an
     * exception: the pool emptying is a routine, self-healing infrastructure
     * event, and a thrown exception would fail the queued job, retry it three
     * times against the same empty pool and leave the operator nothing but a
     * failed-job row. The refusal writes `last_probe_error` instead.
     *
     * @throws RuntimeException When the monitor is not an HTTP monitor, which is
     *                          a defect in the caller rather than an event.
     */
    protected function probe(Monitor $monitor, string $region): CheckResult
    {
        // 1. HTTP only. A TCP check needs a raw socket, which an HTTP forward
        //    proxy cannot carry, and approximating it with a request to
        //    `host:port` would report a connectivity fact about the wrong thing.
        if ($monitor->type !== MonitorType::Http) {
            throw new RuntimeException(
                "Monitor [{$monitor->id}] is a [{$monitor->type->value}] monitor, which this engine cannot probe: "
                .'only http monitors can travel through the proxy pool.'
            );
        }

        // 2. Stamp the attempt's identity before the network, so a refusal
        //    carries the same shape a reading does: `checked_at` is the entry
        //    time (and what `last_probe_error_at` is written from), and
        //    `probe_run_id` is minted per dispatch exactly as
        //    `RelayClient::buildSpec()` mints it, because it is the idempotency
        //    key the processing job de-duplicates on. Both are stamped ONCE for
        //    the whole ladder: a retry through a second exit is the same check,
        //    and minting a second id would let two rows describe one interval.
        $checkedAt = now()->toDateTimeImmutable();
        $probeRunId = (string) Str::uuid();

        // 3. A pool is always preferred: it is the only thing that makes the region
        //    label mean a location. `hasRegion()` also refuses a region absent from
        //    `config('proxy.sources')` even if rows survive in the table, which is
        //    what makes removing a region from config actually remove it.
        if ($this->pool->hasRegion($region)) {
            return $this->runAttemptLadder($monitor, $region, $checkedAt, $probeRunId);
        }

        // 4. ONE region may fall back to leaving from this server itself, and only
        //    the one an operator has named as where the server actually is. That is
        //    what makes "we probe from our own infrastructure" true on a deployment
        //    with no proxy provider wired, where the alternative is measuring
        //    nothing at all.
        if ($region === ProxyRegions::directRegion()) {
            return $this->directAttempt($monitor, $region, $checkedAt, $probeRunId);
        }

        // 5. Any other unsourced region has nowhere to egress from, so it produces
        //    NO VERDICT rather than an exception: the pool emptying is a routine,
        //    self-healing event, and throwing would fail the queued job, retry it
        //    three times against the same empty pool and leave the operator nothing
        //    but a failed-job row. The refusal writes `last_probe_error` instead.
        return $this->failureReading(
            monitor: $monitor,
            region: $region,
            checkedAt: $checkedAt,
            probeRunId: $probeRunId,
            message: $this->exhaustedPoolMessage($region),
            refused: true,
        );
    }

    /**
     * Probe once, straight from this server, with no exit in the path.
     *
     * Structurally separate from {@see runAttemptLadder()} rather than a flag on it,
     * because every safety rule the ladder implements is about an EXIT and none of
     * them applies here. There is no exit to penalise, no alternate exit to
     * corroborate with, and therefore no refusal: with nothing between us and the
     * target, every failure is evidence about the target and becomes an honest
     * `down`.
     *
     * The errno taxonomy specifically must not be reached from here, and that is the
     * reason this is a separate method instead of `blamesTheProxy()` learning about
     * a null exit. On the proxied path errno 7 usually names the proxy, because the
     * proxy is the only host curl dials (see {@see self::PROXY_FAULT_ERRNOS}). On
     * THIS path errno 7 names the target, so running it through that classifier
     * would convert every real outage into a refusal that publishes nothing.
     *
     * One attempt, not `attempts_per_check`: a second identical request from the
     * same server through the same route is not new evidence, only more load on a
     * target that just failed.
     *
     * @throws Throwable When {@see attempt()} fails for a reason that is not a
     *                   transport failure, exactly as the ladder does: laundering a
     *                   defect into a `down` would publish an outage from a bug.
     */
    protected function directAttempt(
        Monitor $monitor,
        string $region,
        DateTimeImmutable $checkedAt,
        string $probeRunId,
    ): CheckResult {
        try {
            return $this->attempt(
                monitor: $monitor,
                region: $region,
                exit: null,
                checkedAt: $checkedAt,
                probeRunId: $probeRunId,
            );
        } catch (HttpClientException $e) {
            return $this->failureReading(
                monitor: $monitor,
                region: $region,
                checkedAt: $checkedAt,
                probeRunId: $probeRunId,
                message: "Region [{$region}]: probed directly from this server (no proxy exit configured) and "
                    ."{$this->targetOf($monitor)} did not answer ({$this->describeCause($e)}).",
                refused: false,
            );
        }
    }

    /**
     * Try exits in the region until one ANSWERS, then decide what the failures
     * collected on the way are evidence of.
     *
     * Bounded by `config('proxy.attempts_per_check')` and nothing else: an
     * unbounded ladder against a genuinely down target multiplies the load on
     * that target and burns a whole region's pool on a single check. Read the
     * class docblock before changing which failure penalises what; the ordering
     * of these branches is the safety property of the transport.
     *
     * @throws Throwable When {@see attempt()} fails for a reason that is not a
     *                   transport failure. That is a defect, and laundering it
     *                   into a `down` reading would publish an outage from a bug.
     */
    protected function runAttemptLadder(
        Monitor $monitor,
        string $region,
        DateTimeImmutable $checkedAt,
        string $probeRunId,
    ): CheckResult {
        $target = $this->targetOf($monitor);
        $ceiling = max(1, (int) config('proxy.attempts_per_check'));

        /** @var list<string> $tried Exit ids already spent, so no exit is retried. */
        $tried = [];

        /** @var list<Proxy> $suspects Ambiguous failures, chargeable only if a later exit answers. */
        $suspects = [];

        $ourFault = false;
        $attempted = 0;
        $cause = '';

        for ($attempt = 1; $attempt <= $ceiling; $attempt++) {
            $exit = $this->pool->take($region, $tried);

            // 1. The pool emptied under us, either between `hasRegion()` and here
            //    or because the ladder itself penalised its way through the
            //    region. Whatever was learned so far decides the outcome below.
            if ($exit === null) {
                break;
            }

            $tried[] = (string) $exit->getKey();
            $attempted++;

            try {
                $reading = $this->attempt(
                    monitor: $monitor,
                    region: $region,
                    exit: $exit,
                    checkedAt: $checkedAt,
                    probeRunId: $probeRunId,
                );
            } catch (HttpClientException $e) {
                $cause = $this->describeCause($e);

                // 2. An errno that names the proxy is OUR failure. The exit
                //    leaves rotation immediately and this attempt is evidence
                //    about us, not about the target.
                if ($this->blamesTheProxy($e)) {
                    $this->pool->penalise($exit);
                    $ourFault = true;

                    continue;
                }

                // 3. Ambiguous. The exit is a SUSPECT, not yet a culprit: only a
                //    later exit answering separates a dead proxy from a dead
                //    target, so the penalty waits for that evidence.
                $suspects[] = $exit;

                continue;
            }

            // 4. A 407 cannot be the target's answer. Same treatment as errno
            //    5/97, and the reading is DISCARDED rather than returned.
            if ($reading->statusCode === self::PROXY_AUTH_REQUIRED) {
                $this->pool->penalise($exit);
                $ourFault = true;
                $cause = 'the exit answered 407 Proxy Authentication Required';

                continue;
            }

            // 5. The target answered. Every earlier exit is now evidenced as the
            //    one at fault, and this one has earned a step of healing.
            foreach ($suspects as $suspect) {
                $this->pool->penalise($suspect);
            }

            $this->pool->reward($exit);

            return $reading;
        }

        // 6. Nothing answered. A verdict is honest only when we could not blame
        //    ourselves for any attempt we actually made.
        if ($attempted === 0) {
            return $this->failureReading(
                monitor: $monitor,
                region: $region,
                checkedAt: $checkedAt,
                probeRunId: $probeRunId,
                message: $this->exhaustedPoolMessage($region),
                refused: true,
            );
        }

        if ($ourFault) {
            return $this->failureReading(
                monitor: $monitor,
                region: $region,
                checkedAt: $checkedAt,
                probeRunId: $probeRunId,
                message: "Region [{$region}]: every proxy exit tried failed on our own side "
                    ."({$cause}), so this probe measured nothing about {$target}.",
                refused: true,
            );
        }

        return $this->failureReading(
            monitor: $monitor,
            region: $region,
            checkedAt: $checkedAt,
            probeRunId: $probeRunId,
            message: "Could not reach {$target}: {$cause}",
            refused: false,
        );
    }

    /**
     * Issue the request through ONE exit and build the reading from whatever the
     * header callback captured.
     *
     * One request, one answer: this method never retries and never touches the
     * pool's health. Swapping exits belongs to {@see runAttemptLadder()}, where
     * the count is bounded and the blame is decided.
     *
     * @throws HttpClientException When the target was never reached, with the
     *                             errno chain intact for
     *                             {@see blamesTheProxy()}. Anything else
     *                             propagates untouched: a defect must not be
     *                             laundered into a reading. See the class
     *                             docblock on why the capture, not the exception,
     *                             decides which of the two happened.
     */
    protected function attempt(
        Monitor $monitor,
        string $region,
        ?Proxy $exit,
        DateTimeImmutable $checkedAt,
        string $probeRunId,
    ): CheckResult {
        $capture = [
            'status' => null,
            'headers' => [],
            'stats' => [],
        ];

        try {
            $response = Http::withHeaders($this->requestHeaders($monitor))
                ->withOptions([
                    ...$this->egressOptions($exit),
                    'allow_redirects' => false,
                    'on_headers' => function (ResponseInterface $response) use (&$capture): void {
                        $capture['status'] = $response->getStatusCode();
                        $capture['headers'] = $response->getHeaders();

                        throw new RuntimeException(self::BODY_ABORTED);
                    },
                    'on_stats' => function (TransferStats $stats) use (&$capture): TransferStats {
                        // Guzzle invokes this from `CurlFactory::finish()` BEFORE
                        // it decides the transfer failed, so the aborted-body
                        // path still gets curl's real numbers. Laravel wraps a
                        // user-supplied `on_stats` rather than replacing it, and
                        // expects the stats back.
                        $capture['stats'] = $stats->getHandlerStats();

                        return $stats;
                    },
                ])
                ->timeout((int) $monitor->timeout_sec)
                ->send(
                    strtoupper($monitor->method?->value ?? 'GET'),
                    $monitor->url,
                    // Omitted rather than sent as an empty string: a zero-length
                    // body is not the same request as no body at all.
                    $monitor->request_body === null ? [] : ['body' => $monitor->request_body],
                );
        } catch (Throwable $e) {
            // 1. THE CAPTURE IS CHECKED FIRST, above any failure classification.
            //    A populated capture is a probe that reached the target, and the
            //    exception is this engine aborting the body. See the class
            //    docblock: the errno says 23 here and the class says
            //    ConnectionException, and neither is evidence of a failure.
            if ($capture['status'] !== null) {
                return $this->readingFrom(
                    monitor: $monitor,
                    region: $region,
                    exit: $exit,
                    checkedAt: $checkedAt,
                    probeRunId: $probeRunId,
                    statusCode: $capture['status'],
                    headers: $capture['headers'],
                    handlerStats: $capture['stats'],
                );
            }

            // 2. Nothing captured: the target was never reached. Rethrown as-is
            //    so the errno chain stays readable through `getPrevious()`, which
            //    is where {@see runAttemptLadder()} decides whether this was our
            //    exit or the target.
            throw $e;
        }

        // 3. The transfer finished without the callback firing. In production
        //    that means Guzzle never reached the curl handler; in tests it is
        //    `Http::fake`, whose stub handler short-circuits above `CurlFactory`.
        //    The reading is built from the returned response instead, by the same
        //    rules, so one code path decides the status either way.
        return $this->readingFrom(
            monitor: $monitor,
            region: $region,
            exit: $exit,
            checkedAt: $checkedAt,
            probeRunId: $probeRunId,
            statusCode: $response->status(),
            headers: $response->toPsrResponse()->getHeaders(),
            handlerStats: $response->handlerStats(),
        );
    }

    /**
     * The transfer options that put this request on a third-party exit.
     *
     * The `proxy` option is passed EXPLICITLY on every request, never left to
     * Guzzle's default resolution, which would otherwise adopt an ambient
     * `http_proxy`/`https_proxy` from the environment and quietly change where a
     * probe leaves from.
     *
     * The credentials travel as a curl option and are never interpolated into the
     * proxy address as userinfo. curl's URL parser splits the credential on the
     * FIRST `@` and ends the authority at the first raw `/`, `?` or `#`, so a
     * provider password containing any of those corrupts the parse SILENTLY: the
     * probe then egresses unauthenticated, the proxy answers 407, and that 407
     * gets recorded as the target's own answer. `CURLOPT_PROXYUSERPWD` bypasses
     * that parser (guzzle `CurlFactory.php:42` lists it as a first-class proxy
     * credential option), and curl sends it preemptively as
     * `Proxy-Authorization: Basic ...`, which the live test asserts on the wire.
     *
     * @return array{proxy: string, curl: array<int, string>}
     */
    protected function egressOptions(?Proxy $exit): array
    {
        // No exit means the direct path, and the empty string is how this codebase
        // says "deliberately not through a proxy" (same opt-out as
        // `ProxyListFetcher`, and the only one Guzzle's curl handler honours, since
        // `false` throws). Passing it EXPLICITLY rather than omitting the key keeps
        // the plan's hardest invariant literally true: every request this engine
        // makes states its egress, so an ambient `http_proxy` in the environment can
        // never quietly become one.
        if ($exit === null) {
            return ['proxy' => ''];
        }

        $credentials = $exit->credentials;

        return [
            'proxy' => 'http://'.$exit->host.':'.$exit->port,
            'curl' => [
                CURLOPT_PROXYUSERPWD => ($credentials['username'] ?? '').':'.($credentials['password'] ?? ''),
                // WITHOUT THIS, EVERY HTTPS PROBE PUBLISHES THE PROXY'S GREETING AS THE
                // TARGET'S ANSWER. curl hands the CONNECT reply's headers to the header
                // callback by default, so Guzzle builds a Response from
                // `HTTP/1.1 200 Connection established` and fires `on_headers` with it.
                // The capture-first discriminator then reads that as "we reached the
                // target". Measured against a listener that answered CONNECT with 200 and
                // closed, so the target was never contacted at all: the capture held
                // `status 200, 0 headers, reason "Connection established"`, and the engine
                // would have returned `up` for `https://github.com` without sending it a
                // single byte, no TLS handshake, no GET, no User-Agent. All eight catalog
                // monitors are HTTPS with an expected 200, so all eight public pages would
                // have published "We reached it normally" about a request that never
                // happened. Suppressing the CONNECT headers is what makes the capture the
                // TARGET's response; verified after the change that the capture carries the
                // target's own status and its real headers, and that the body abort still
                // reports zero bytes downloaded.
                CURLOPT_SUPPRESS_CONNECT_HEADERS => true,
            ],
        ];
    }

    /**
     * Assemble the worker's wire payload from what was observed and parse it back
     * through the shared parser.
     *
     * @param  array<string, list<string>>  $headers  PSR-7 shaped response headers.
     * @param  array<string, mixed>  $handlerStats  curl's own transfer stats, empty
     *                                              when no transfer happened.
     */
    protected function readingFrom(
        Monitor $monitor,
        string $region,
        ?Proxy $exit,
        DateTimeImmutable $checkedAt,
        string $probeRunId,
        int $statusCode,
        array $headers,
        array $handlerStats,
    ): CheckResult {
        $headers = $this->normaliseHeaders($headers);

        // A bot challenge is the target's edge declining to let this engine
        // measure anything, so it produces no verdict, exactly as the relay's
        // `regional-probe.ts` decides for the same response. Measured on
        // production: openai.com and claude.ai answered every check with 403,
        // `cf-mitigated: challenge` and an interactive page while serving
        // browsers fine, and two "is down" incidents stayed open over it.
        //
        // The status code travels with the refusal on purpose; see
        // {@see self::failureReading()} and {@see self::recordRegionHealth()}.
        $challenge = $this->describeBotChallenge($monitor, $headers, $statusCode);
        if ($challenge !== null) {
            return $this->failureReading(
                monitor: $monitor,
                region: $region,
                checkedAt: $checkedAt,
                probeRunId: $probeRunId,
                message: $challenge,
                refused: true,
                statusCode: $statusCode,
            );
        }

        $totalTime = $handlerStats['total_time'] ?? null;

        return CheckResult::fromWorkerPayload([
            'monitor_id' => (string) $monitor->getKey(),
            'region' => $region,
            'checked_at' => $checkedAt->format(DateTimeImmutable::ATOM),
            'status' => $this->determineStatus($statusCode, (int) $monitor->expected_status_code)->value,
            'status_code' => $statusCode,
            'response_ms' => $totalTime === null ? null : (int) round((float) $totalTime * 1000),
            'error_message' => null,
            'timing' => $this->timingFrom($handlerStats),
            'response_headers' => $headers,
            // Null, not an empty string: this engine reads no body. See the class
            // docblock for why, and `CheckResult::$content` for why it is legal.
            'response_body_preview' => null,
            'probe_run_id' => $probeRunId,
            // `colo` is deliberately absent: it is the Cloudflare colo, evidence
            // this engine cannot produce, and inventing one would make the region
            // look verified when it is not. `exit_via` is what this engine has
            // instead: the exit that actually answered, see CheckResult::$exitVia.
            // Null on the direct path: there was no exit, and null is already how
            // this column reads absence.
            'exit_via' => $exit === null ? null : $exit->host.':'.$exit->port,
            'probe_refused' => false,
            'content' => null,
            'content_type' => $headers['content-type'] ?? null,
            'content_truncated' => false,
        ]);
    }

    /**
     * A CheckResult for a probe that produced no response at all.
     *
     * Two outcomes, one builder, because the wire payload is identical apart from
     * the flag: `$refused = true` is OUR failure (no verdict, no check row, only
     * `last_probe_error`), `$refused = false` is an honest `down` about a target
     * every exit we tried could not reach. `status` is `down` in BOTH cases,
     * which is what `regional-probe.ts:292` puts on the wire: a reader that
     * predates `probe_refused` sees the conservative value, and everything
     * current branches on the flag instead.
     *
     * `$statusCode` is null for every caller but the bot challenge, the one
     * non-verdict that arrived WITH a response. It is not decoration there:
     * {@see self::recordRegionHealth()} reads a status code on a refusal as
     * proof the region's exits carried the request, which is what separates
     * "the target would not let us measure" from "our egress is dark".
     */
    protected function failureReading(
        Monitor $monitor,
        string $region,
        DateTimeImmutable $checkedAt,
        string $probeRunId,
        string $message,
        bool $refused,
        ?int $statusCode = null,
    ): CheckResult {
        return CheckResult::fromWorkerPayload([
            'monitor_id' => (string) $monitor->getKey(),
            'region' => $region,
            'checked_at' => $checkedAt->format(DateTimeImmutable::ATOM),
            'status' => MonitorStatus::Down->value,
            'status_code' => $statusCode,
            'response_ms' => null,
            'error_message' => $message,
            // Nothing was measured, so nothing is reported: the worker's
            // `emptyTiming()` for exactly this case.
            'timing' => [],
            'response_headers' => [],
            'response_body_preview' => null,
            'probe_run_id' => $probeRunId,
            'probe_refused' => $refused,
            'content' => null,
            'content_type' => null,
            'content_truncated' => false,
        ]);
    }

    /**
     * The outgoing headers: our identity first, the monitor's own over it.
     *
     * `resources/legal/bot.en.md` tells every operator that both clients
     * identify themselves with the same string and that blocking it stops us,
     * and only the feed reader was doing it. The availability check is the
     * larger of the two by a wide margin, so the published page was untrue about
     * the client an operator is actually seeing, and the advice it gives was
     * unactionable. It also makes the challenge branch above mean something: a
     * block we honour has to be a block a target can express.
     *
     * The monitor's own header wins, whatever case it is written in, which is
     * why the merge is by lowercased key rather than a plain array union. Guzzle
     * sends what it is given, so `User-Agent` beside `user-agent` is two headers
     * on the wire, and the target would match neither.
     *
     * @return array<string, string>
     */
    protected function requestHeaders(Monitor $monitor): array
    {
        $headers = ['User-Agent' => (string) config('uptizm.bot_user_agent')];

        foreach ((array) $monitor->request_headers as $name => $value) {
            $existing = array_key_first(array_filter(
                $headers,
                fn (string $key): bool => strcasecmp($key, (string) $name) === 0,
                ARRAY_FILTER_USE_KEY,
            ));

            unset($headers[$existing]);
            $headers[(string) $name] = (string) $value;
        }

        return $headers;
    }

    /**
     * The operator-facing sentence for a response that is a bot challenge, or
     * null when the response is an ordinary one.
     *
     * The PHP half of `describeBotChallenge()` in `regional-probe.ts`, and it
     * has to stay the same rule: the two transports serve the same monitors, so
     * a target unmeasurable through one is unmeasurable through the other.
     *
     * Read from the header the challenge declares itself with rather than
     * inferred from a status code. `cf-mitigated` is Cloudflare's own signal,
     * and that is the difference between a measurement and a guess: a 403 with
     * no such header is the target answering and must stay a real outage, which
     * `test_a_client_error_is_down_and_is_still_a_reading` pins.
     *
     * Any value at all counts, not `challenge` alone. Cloudflare may add
     * siblings, and the property this turns on is that the request was
     * mitigated rather than served.
     *
     * @param  array<string, string>  $headers  Lowercased response headers.
     */
    protected function describeBotChallenge(Monitor $monitor, array $headers, int $statusCode): ?string
    {
        $mitigated = trim($headers['cf-mitigated'] ?? '');

        if ($mitigated === '') {
            return null;
        }

        $target = parse_url($monitor->url, PHP_URL_HOST) ?: $monitor->url;

        return "{$target} served a bot challenge instead of a response "
            ."(HTTP {$statusCode}, cf-mitigated: {$mitigated}). The probe measured "
            .'nothing about the service, so no check was recorded. Point the monitor at '
            .'an endpoint the target does not challenge, such as a health or status API.';
    }

    /**
     * Whether a transport failure names OUR OWN EXIT rather than the target.
     *
     * Its own named predicate on purpose: it keeps "is this a proxy problem"
     * greppable and independent of "did the check fail", which is what lets an
     * exit be marked dead without touching a monitor's status. It has one caller,
     * so it stays a method here rather than becoming a classifier class; this
     * plan requires three callers before an extraction.
     *
     * The errno is read through {@see handlerContextOf()}, which goes to
     * `getPrevious()` and never to the exception handed in: every transport
     * failure surfaces as `Illuminate\Http\Client\ConnectionException`, which has
     * no `getHandlerContext()` at all, and Guzzle's own exception underneath is
     * what carries it. Measured on this machine: an unresolvable proxy host
     * yields ConnectionException -> RequestException with `errno = 5`.
     *
     * Anything unrecognised answers FALSE, which is the safe default because
     * false means "ambiguous": it costs one alternate exit, where a wrong true
     * would suppress a real outage.
     *
     * The ORDER below is the whole design, because libcurl moved a failure between
     * errnos across 8.19.0/8.20.0 while keeping its message identical. So the
     * message is asked FIRST wherever a tunnel is named, and the errno decides only
     * what the message left open. That way one classifier is correct on both curl
     * generations with no runtime version check, and an upgrade of the box cannot
     * silently re-point a branch. See {@see self::PROXY_FAULT_ERRNOS} for the tags
     * the change was verified at.
     */
    protected function blamesTheProxy(Throwable $e): bool
    {
        $context = $this->handlerContextOf($e);
        $errno = $context['errno'] ?? null;

        if ($errno === null) {
            return false;
        }

        $errno = (int) $errno;
        $message = (string) ($context['error'] ?? '');

        // 1. The codes that name the proxy in their own definition.
        if (in_array($errno, self::PROXY_FAULT_ERRNOS, true)) {
            return true;
        }

        // 2. An errno a tunnel failure cannot arrive as is ambiguous, whatever it
        //    says. This is what keeps `Proxy CONNECT aborted due to timeout`
        //    (errno 28) out of the arms below.
        if (! in_array($errno, self::TUNNEL_FAILURE_ERRNOS, true)) {
            return false;
        }

        // 3. The proxy ANSWERED the CONNECT, so the code it answered with decides,
        //    and only 407 is about the proxy. Returning from inside this branch
        //    rather than falling through is deliberate: a 502 means the ORIGIN
        //    refused, which is a real outage, and it must not reach arm 5 below and
        //    be read as our own failure on a libcurl that reports it as errno 7.
        if (Str::contains($message, self::TUNNEL_REPLY_MARKER)) {
            return Str::contains($message, array_map(
                static fn (string $code): string => self::TUNNEL_REPLY_MARKER.$code,
                self::PROXY_OWN_REPLY_CODES,
            ));
        }

        // 4. A tunnel that produced no reply at all: our exit's own half of the
        //    handshake failed, and no target byte was ever involved.
        if (Str::contains($message, self::TUNNEL_ABORTED_SIGNATURES)) {
            return true;
        }

        // 5. Nothing named a tunnel. An errno 7 left here is the ordinary
        //    couldn't-connect, and the only host we dial is the proxy.
        return $errno === self::CONNECT_FAILED_ERRNO;
    }

    /**
     * curl's handler context, from whichever of Guzzle's TWO shapes carries it.
     *
     * `CurlFactory` picks the exception class off a fixed `connectionErrors` list
     * (`CurlFactory.php:1082-1088`): errnos 6, 7, 28, 35 and 52 become a
     * `ConnectException`, everything ELSE, 5 and 97 included, becomes a
     * `RequestException`. Both classes expose `getHandlerContext()` and NEITHER
     * inherits it: their only common ancestor is `TransferException`, which does
     * not declare it. So a single `instanceof` here reads an empty context for
     * half of all real failures. Measured: with only the `RequestException` arm,
     * every honest `down` this engine produced for errno 7 or a timeout, which is
     * to say the common case, carried no cause at all in
     * `monitor_checks.error_message`.
     *
     * The classification is unaffected by which class arrives, and that is the
     * point of reading the context rather than the class: 5 and 97 happen to sit
     * outside Guzzle's list today, and nothing about this engine's answer should
     * depend on that staying true.
     *
     * @return array<string, mixed>
     */
    protected function handlerContextOf(Throwable $e): array
    {
        $previous = $e->getPrevious();

        return match (true) {
            $previous instanceof GuzzleConnectException => $previous->getHandlerContext(),
            $previous instanceof GuzzleRequestException => $previous->getHandlerContext(),
            default => [],
        };
    }

    /**
     * curl's own account of why a transfer failed: the operator-readable part of
     * the exception, and the only part safe to store.
     *
     * The message Guzzle builds appends ` for <url>`, and this string lands on
     * the check row and is rendered in the UI, where a monitor's URL can carry a
     * token in its query string. curl's `error` names the EXIT it could not use
     * instead, which is the part an operator acts on. Same rule the worker
     * applies in `describeProbeFailure()`/`probeTarget()`, and the reason the raw
     * exception message is never pasted through.
     *
     * Guzzle sanitizes this field itself before it reaches the context
     * (`CurlFactory::sanitizeCurlError()` redacts proxy userinfo and strips the
     * base URI), which is the second reason to prefer it over the message.
     */
    protected function describeCause(Throwable $e): string
    {
        $error = $this->handlerContextOf($e)['error'] ?? null;

        return is_string($error) && trim($error) !== ''
            ? $error
            : 'the transport failed without reporting a reason';
    }

    /**
     * The host a probe was aiming at, and only the host.
     *
     * Mirrors the worker's `probeTarget()`, for the same reason
     * {@see describeCause()} exists: this string is stored and displayed, and a
     * URL can carry a secret. Falls back to the raw url when it will not parse,
     * exactly as the worker does.
     */
    protected function targetOf(Monitor $monitor): string
    {
        return parse_url((string) $monitor->url, PHP_URL_HOST) ?: (string) $monitor->url;
    }

    /**
     * The refusal message for a region that has nothing left to egress through.
     *
     * Shared by the pre-flight check and the ladder because they are the same
     * condition observed a moment apart, and an operator reading
     * `last_probe_error` should not have to tell them apart.
     */
    protected function exhaustedPoolMessage(string $region): string
    {
        return "Region [{$region}] has no usable proxy exit, so this probe did not run: every exit in "
            .'the regional pool is out of rotation, or the region has no configured source.';
    }

    /**
     * The three-way status rule, reproduced from `regional-probe.ts:489-497`.
     *
     * An exact match is up; any OTHER 2xx or 3xx is degraded, because the target
     * answered and answered differently; everything else is down. Both transports
     * write into one table, so this arithmetic has to agree with the worker's or a
     * catalog page flips status on nothing but which network ran the probe.
     */
    protected function determineStatus(int $statusCode, int $expected): MonitorStatus
    {
        if ($statusCode === $expected) {
            return MonitorStatus::Up;
        }

        if ($statusCode >= 200 && $statusCode < 400) {
            return MonitorStatus::Degraded;
        }

        return MonitorStatus::Down;
    }

    /**
     * Turn curl's cumulative timestamps into the worker's phase lengths.
     *
     * curl reports each milestone as seconds SINCE THE START of the transfer,
     * while the payload's `timing` block is a set of phase LENGTHS, so every field
     * is a difference between two milestones. Kept as a pure function of the stats
     * array because the only other way to reach it is a live request, and an
     * arithmetic slip here is invisible in production.
     *
     * An empty array (no transfer, so nothing measured) reads as zeros rather than
     * as negatives. Read the class docblock before trusting `connect_ms` or
     * `tls_ms` as the same measurement the worker reports.
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, int>
     */
    protected function timingFrom(array $stats): array
    {
        $dnsEnd = (float) ($stats['namelookup_time'] ?? 0.0);
        $connectEnd = (float) ($stats['connect_time'] ?? 0.0);
        $tlsEnd = (float) ($stats['appconnect_time'] ?? 0.0);
        $firstByte = (float) ($stats['starttransfer_time'] ?? 0.0);
        $total = (float) ($stats['total_time'] ?? 0.0);

        return [
            'dns_ms' => $this->phaseMs($dnsEnd, 0.0),
            'connect_ms' => $this->phaseMs($connectEnd, $dnsEnd),
            // TLS is the one milestone curl reports as ABSENT rather than as a
            // zero-length phase: `appconnect_time` is 0.0 on a cleartext hop, so
            // it cannot be subtracted from blindly.
            'tls_ms' => $tlsEnd > 0.0 ? $this->phaseMs($tlsEnd, $connectEnd) : 0,
            'ttfb_ms' => $this->phaseMs($firstByte, max($connectEnd, $tlsEnd)),
            'download_ms' => $this->phaseMs($total, $firstByte),
        ];
    }

    /**
     * One phase length in whole milliseconds, floored at zero so a milestone curl
     * never reached cannot produce a negative duration.
     */
    protected function phaseMs(float $end, float $start): int
    {
        return (int) round(max(0.0, $end - $start) * 1000);
    }

    /**
     * Flatten PSR-7 headers to the worker's `Record<string, string>` shape.
     *
     * Lower-cased names and repeated fields joined with `, `, which is what the
     * worker's `extractHeaders()` produces from the Fetch API's `Headers`. The
     * shape has to match: one table stores both, and `MetricExtractor` reads it.
     *
     * @param  array<string, list<string>>  $headers
     * @return array<string, string>
     */
    protected function normaliseHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $name => $values) {
            $normalised[strtolower((string) $name)] = implode(', ', $values);
        }

        return $normalised;
    }

    /**
     * Record this attempt's outcome against the region's health row.
     *
     * THE DISCRIMINATOR IS WHETHER THIS PROBE PRODUCED A READING AT ALL, which is
     * what `probeRefused` means and is exactly the question the alarm asks ("has
     * produced no reading for several consecutive intervals"). A target the engine
     * reached and found `down` still produced a reading and still proves the
     * region's exits carried a request, so it clears `consecutive_empty_intervals`
     * exactly like an `up` would.
     *
     * The absence of a status code is NOT that discriminator, and an earlier
     * revision that added it filed a working region as dark: a target refusing the
     * connection through a perfectly healthy tunnel returns `down` with no status
     * code, so every genuinely down catalog target would have counted an empty
     * interval against the region that measured it, and three intervals later the
     * operator gets a dark-region alarm for a region that wrote check rows the
     * whole time. What that revision was actually reaching for is a provider-wide
     * failure, where every exit fails to connect; that case is now decided where it
     * belongs, in {@see self::PROXY_FAULT_ERRNOS} (errno 7), so it arrives here as a
     * refusal and this branch sees it without needing to guess from a null field.
     *
     * One residual is ACCEPTED here rather than fixed, because the per-attempt data
     * cannot separate it. A provider that accepts TCP and then never completes the
     * CONNECT yields errno 28, which is ambiguous by this engine's own rule (a
     * timeout inside CONNECT may be the proxy waiting on the target), so such a
     * provider reads as healthy while publishing `down` readings. `connect_time`
     * looked like the missing discriminator and is not: with a proxy in the path
     * curl stamps `TIMER_CONNECT` only once the tunnel is up, so a black-holed
     * proxy IP and a proxy that accepts and hangs both measure `connect_time = 0.0`.
     * What stands between that and a public outage claim is the CROSS-REGION quorum
     * ({@see ServicePageAssembler::MIN_AGREEING_REGIONS}), and it only helps if the
     * regions are sourced from different vendors, which is an ops fact this code
     * cannot see. The remaining lever would be cross-monitor correlation inside one
     * region and tick, which belongs in the alarm rather than in a verdict.
     *
     * `healthy_proxy_count` is read fresh from {@see Proxy} on every write
     * rather than trusted from an earlier one: the refresher that owns the
     * refresh cadence for this column is not part of this engine, so reading
     * it live here is what keeps the count no staler than the last attempted
     * probe rather than the last hourly refresh.
     *
     * `alarmed_at` is cleared on every non-refused attempt, and ONLY here:
     * {@see AlarmDarkProbeRegions} sets it and never clears it, so a region
     * that recovers and later goes dark again can alarm a second time
     * instead of being silenced for the table's lifetime by its first alarm.
     */
    protected function recordRegionHealth(CheckResult $result): void
    {
        $health = ProbeRegionHealth::query()->firstOrCreate(['region' => $result->region]);

        $healthyProxyCount = Proxy::query()->healthy()->region($result->region)->count();

        // The engine records TIMESTAMPS and the pool size, and deliberately does
        // NOT touch `consecutive_empty_intervals`. It cannot: the engine runs once
        // per (monitor, region), so 8 catalog monitors in one tick would advance a
        // per-interval counter 8 times. Measured before this was moved: one dark
        // tick took the streak to 8 against a threshold of 3, so the alarm fired on
        // the FIRST dark tick and the "a single missed tick is normal" rule was
        // dead. Counting intervals is the scheduled job's job, because the job is
        // the only thing here that runs once per interval.
        // A refusal that carries a STATUS CODE is the one refusal the exits are
        // innocent of: the only way to have a status code is to have received a
        // response, so the tunnel carried the request and the target answered.
        // Today that is the bot challenge, and treating it as evidence of a dark
        // region would be a measurable defect rather than a theoretical one:
        // `AlarmDarkProbeRegions` counts an interval empty when
        // `last_failure_at >= last_success_at`, this engine writes the row once
        // per (monitor, region), and a region holding two challenged catalog
        // targets out of eight ends roughly a quarter of its ticks on one. Three
        // of those in a row is common enough to alarm a region that wrote check
        // rows the whole time, which is the exact failure the docblock above
        // records an earlier revision producing from the opposite mistake.
        if ($result->probeRefused && $result->statusCode === null) {
            $health->update([
                'last_failure_at' => now(),
                'healthy_proxy_count' => $healthyProxyCount,
            ]);

            return;
        }

        $health->update([
            'last_success_at' => now(),
            'healthy_proxy_count' => $healthyProxyCount,
            'consecutive_empty_intervals' => 0,
            'alarmed_at' => null,
        ]);
    }

    /**
     * Refuse any monitor the internal system team does not own.
     *
     * Reads through the relation rather than a config value or an id list, so
     * the answer is the same one `PlanGate::limits()` already trusts in
     * production. A monitor with no team resolves to null and is refused: an
     * unknown owner is not the system team.
     *
     * @throws RuntimeException When the monitor's team is not the system team.
     */
    protected function assertSystemOwned(Monitor $monitor): void
    {
        if ($monitor->team?->is_system === true) {
            return;
        }

        throw new RuntimeException(
            "Monitor [{$monitor->id}] is not owned by the system team, so it "
            .'may not be probed from this server.'
        );
    }
}
