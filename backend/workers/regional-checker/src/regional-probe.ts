/**
 * RegionalProbe Durable Object.
 *
 * One instance per region (`idFromName("us-east")`, ...). Instances are
 * pinned to a `locationHint` at creation so the outbound probe fetch
 * leaves Cloudflare's network from the requested geography rather than
 * the colo closest to the API origin.
 *
 * The DO is deliberately stateless: it owns no storage. The probe logic
 * (fetch + timing + status classification) lives here so the request
 * path is `Laravel -> Worker (edge) -> DO (region-pinned) -> target`,
 * and the {@link CheckResultPayload} is returned INLINE in the /run
 * response (synchronous relay, no callback).
 *
 * The payload carries `probe_run_id` verbatim from the request: Laravel
 * dedups on it, so echoing it back is the idempotency key of the whole
 * round trip.
 */

import { connect } from "cloudflare:sockets";

import {
    type AssertionSubject,
    evaluateAssertions,
} from "./assertions";
import {
    emptyTiming,
    type TimingBreakdown,
} from "./timing";

type ProbeRequest = {
    monitor_id: string;
    probe_run_id: string;
    region: string;
    type: "http" | "tcp";
    method: string | null;
    url: string;
    request_headers: Record<string, string> | null;
    request_body: string | null;
    timeout_seconds: number;
    expected_status_code: number;
    /**
     * The monitor's credential map, decrypted by the origin before signing.
     *
     * Shaped by `HttpAuthType` on the Laravel side: `none`, `basic`
     * (`username` + `password`), `bearer` (`token`), or `api_key` (`key` +
     * `header`). Applied in `authHeaders()`.
     */
    auth_config: AuthConfig | null;

    /**
     * The response assertions this probe evaluates against its own reading.
     *
     * Null, or an empty list, means NO ASSERTIONS, which is a different state
     * from "every assertion passed": see {@link CheckResultPayload.assertions}.
     *
     * Typed as the shape the origin PROMISES rather than as what the wire can
     * physically carry, exactly as {@link AuthConfig} is: the whole spec is cast
     * from `request.json()`, so nothing in it has been validated when the
     * evaluator receives it. These rules are operator-authored JSON living in a
     * `jsonb` column, and the save-time validator that narrows them to this shape
     * lands with the evaluator, so a row written before it existed can hold
     * anything at all. The evaluator therefore guards every field at runtime and
     * records what it could not evaluate, in the same spirit as the `default`
     * branch of {@link authHeaders}.
     */
    assertion_rules: AssertionRule[] | null;

    /**
     * The body ceiling in bytes, mirrored from the origin's
     * `content-archive.max_bytes`.
     *
     * It travels on the signed spec rather than living here so raising the
     * origin's config actually raises the ceiling. Optional because a spec from
     * an origin older than the content archive omits it; see
     * {@link CONTENT_MAX_BYTES_FALLBACK}.
     */
    max_bytes?: number;

    /**
     * The content types whose body may be returned, mirrored verbatim from the
     * origin's `content-archive.allowed_content_types`.
     *
     * ABSENT MEANS NOTHING IS ALLOWED: an origin that does not send the list has
     * nowhere to put a body, so it keeps the preview-only behaviour it expects.
     * Do NOT introduce a default list here; the whole point of routing the
     * allowlist through the signed spec is that widening it stays a one-sided
     * config change on the origin.
     */
    allowed_content_types?: string[];
};

type CheckResultPayload = {
    monitor_id: string;
    probe_run_id: string;
    region: string;
    checked_at: string;
    status: "up" | "down" | "degraded";
    status_code: number | null;
    response_ms: number | null;
    error_message: string | null;
    timing: TimingBreakdown;
    response_headers: Record<string, string>;
    response_body_preview: string | null;

    /**
     * The Cloudflare colo the probe actually ran from, e.g. `FRA`.
     *
     * It travels in the BODY as well as the `x-probe-colo` header, because the
     * API reads only the body and the header was therefore discarded. Without it
     * the `region` on a stored check is an echo of what the caller asked for
     * rather than evidence of where the probe ran, and a mis-mapped
     * `locationHint` would produce identical results under different region
     * labels with nothing to catch it.
     *
     * `unknown` when the probe failed before the colo could be resolved.
     */
    colo: string;

    /**
     * True when the EDGE refused to perform the probe, as opposed to the target
     * failing it.
     *
     * `connect()` rejects a raw TCP connection to a host it serves over HTTP,
     * which is every Cloudflare-proxied hostname. That is a fact about our
     * platform, not about the customer's service, so the API must not let it
     * count as a failed check: doing so opens an incident and pages someone for
     * a target that is up.
     */
    probe_refused: boolean;

    /**
     * The DECODED response body, up to {@link ProbeRequest.max_bytes}.
     *
     * Reading the body is what makes the runtime decompress it, so this is plain
     * content whether the target served gzip, brotli or nothing. That matters
     * because the API hashes it: the same page compressed twice is not the same
     * bytes, so a still-compressed body would dedupe to nothing.
     *
     * Null for a TCP probe, for a failed probe, and for a response whose content
     * type falls outside {@link ProbeRequest.allowed_content_types}. Filtering
     * HERE rather than on the API side means a body the archive would refuse
     * never crosses the wire at all.
     */
    content: string | null;

    /**
     * The raw `content-type` response header, or null when the response carried
     * none.
     *
     * Sent even when {@link CheckResultPayload.content} was filtered out: which
     * type was rejected is what makes a missing archive explainable rather than
     * mysterious.
     */
    content_type: string | null;

    /**
     * True when the body reached the ceiling and the remainder was discarded, so
     * {@link CheckResultPayload.content} is a prefix of what the target served
     * rather than the whole of it.
     */
    content_truncated: boolean;

    /**
     * The outcome of evaluating {@link ProbeRequest.assertion_rules}, or null when
     * the monitor configured none.
     *
     * ONE nullable field rather than two, and that is the load-bearing part. The
     * destination is a pair of columns, `monitor_checks.assertions_passed` (a
     * boolean that becomes nullable for this) and `assertion_results` (`jsonb`),
     * where NULL means "not evaluated". Two nullable fields on the wire would make
     * four states representable, of which two are contradictions: a verdict with
     * no results, and results with no verdict. Either would land as one populated
     * column beside one NULL with nothing to say which of the two lied. Nesting
     * them under a single nullable report leaves "no rules" and "rules ran" as the
     * only shapes that exist, and `timing` already proves a nested object survives
     * `CheckResult::fromWorkerPayload()`.
     *
     * A failed assertion is published as `down` on the `status` field above and as
     * nothing else. None of the seven products surveyed for this design has an
     * assertion-failed severity: a 200 with a failed body assertion pages exactly
     * like a refused connection, so there is no third state to introduce here.
     */
    assertions: AssertionReport | null;
};

/**
 * What an assertion may be evaluated against.
 *
 * These four are the whole v1 vocabulary, and `json_path` is deliberately absent
 * rather than merely unimplemented. Two things have to be settled before it can
 * be added, and neither is a detail:
 *
 * 1. The dialect is a fork in the road. Checkly cites RFC 9535, Grafana's
 *    MultiHTTP cites the older Goessner draft, and the two diverge on filter
 *    expressions and slices, so the same path expression means different things
 *    depending on which product the operator arrived from. Picking one silently
 *    breaks the other's mental model, and either pick drags a JSONPath
 *    implementation into the edge bundle.
 * 2. A path that returns several values quietly becomes an all-elements check.
 *    Checkly documents a path returning `[1,5,2]` compared "less than 3" as
 *    FAILING, because every returned element is AND-chained. Nothing in a panel
 *    that reads like a scalar comparison would tell an operator that.
 *
 * So adding it is a design decision with those two questions answered, not a new
 * member on this union. TLS expiry and response size are absent for the duller
 * reason: nobody has asked.
 */
export type AssertionTarget =
    | "status_code"
    | "response_time_ms"
    | "body"
    | "header";

/**
 * How an assertion compares its target against its value.
 *
 * These ten are the set that converged across Better Stack, Checkly, Datadog
 * Synthetics, Pingdom, UptimeRobot, Grafana Synthetic Monitoring and k6: it is
 * Checkly's and UptimeRobot's published list and a superset of Datadog's
 * per-field table, while Pingdom and Better Stack are contains-only subsets of
 * it. There is no OR and no per-rule severity because no surveyed product offers
 * either; Datadog's own users fake alternation with a `200|302` regex, which this
 * set supports as-is.
 *
 * THIS UNION IS THE SOURCE OF TRUTH for the vocabulary. The save-time validator
 * under `backend/app/Support/Monitoring/` enumerates exactly these and nothing
 * else, the same two-sided mirror that binds {@link contentTypeAllowed} to
 * `App\Support\Monitoring\ContentTypeAllowList`: change one side and the other
 * must move with it, or the panel accepts a rule the edge can only skip.
 *
 * `matches_regex` and `not_matches_regex` carry a hazard the other eight do not.
 * The pattern is operator-supplied and stored, and JavaScript's RegExp
 * backtracks, so it is a reachable ReDoS on a runtime with a CPU budget; Grafana
 * picked Go's RE2 for exactly this and got linear-time matching for it. There is
 * no RE2 at this edge by decision, so the containment is a save-time screen (a
 * length cap, nested-quantifier detection, a trial compile) plus a hard body
 * ceiling before any pattern runs. Never run a stored pattern against an
 * unbounded body.
 */
export type AssertionOperator =
    | "equals"
    | "not_equals"
    | "contains"
    | "not_contains"
    | "greater_than"
    | "less_than"
    | "matches_regex"
    | "not_matches_regex"
    | "exists"
    | "not_exists";

/**
 * The value a rule compares its target against.
 *
 * Numbers arrive as JSON numbers for `status_code` and `response_time_ms`, text
 * for `body` and `header`, and `exists` / `not_exists` carry none at all (the key
 * absent, or null). A single `string` was rejected because it throws away the
 * intent the operator expressed and turns every numeric rule into a parse at the
 * far end; `unknown` was rejected because it defers the same decision to the
 * evaluator with no compile-time help and the evaluator would rebuild this union
 * by hand.
 *
 * The cost of the union is one normalisation step per comparison, and one of
 * those steps is not optional: numeric equality must NOT be `===`. UptimeRobot's
 * own worked example equality-compares a JSON price of `0.000003`, so an epsilon
 * or a normalised string form is required. A value of the wrong kind for the
 * target is a `value_invalid` skip, never a failure.
 */
export type AssertionValue = string | number | null;

/**
 * One response assertion, as the origin stores and forwards it.
 *
 * `assertions.ts` reads these types through `import type`, which erases at
 * compile time. Never export a VALUE from here for the evaluator to import: this
 * module imports the evaluator, so a value import would close a runtime cycle.
 */
export type AssertionRule = {
    target: AssertionTarget;
    operator: AssertionOperator;

    /** See {@link AssertionValue}; absent for `exists` and `not_exists`. */
    value?: AssertionValue;

    /**
     * The response header to read, required when and only when `target` is
     * `header`.
     *
     * Optional here rather than modelled as a discriminated union on `target`,
     * following {@link AuthConfig}: the pairing is enforced where the rule is
     * written, and a type that forbids the broken shape would make the runtime
     * guard against it look dead while the wire still carries it. A `header` rule
     * without a name is a `header_name_missing` skip.
     */
    name?: string | null;
};

/**
 * The value the probe actually measured, recorded on the outcome.
 *
 * Bounded for the two text targets, and that bound is not cosmetic. This rides
 * inside `assertion_results`, which reaches the persisting job through
 * `CheckResult::toArray()` and therefore through the Redis `processing` queue.
 * `content` is excluded from that array precisely so a 1 MB page never enters
 * Redis, where queue keys carry no TTL and the `volatile-lru` eviction victims
 * are the persistence locks. Recording a whole body as the observed value would
 * reintroduce that through the back door, so the evaluator records a short
 * excerpt (256 characters) and never more; the constant belongs with the
 * evaluator.
 *
 * Null when there was nothing to measure: a header the response did not carry, or
 * a rule skipped before it read anything.
 */
export type AssertionObserved = string | number | null;

/**
 * Why a rule was not evaluated, as a closed set.
 *
 * Closed and not an open string on purpose: the save-time validator rejects most
 * of these before they can be stored, and a fixed set is what lets the two sides
 * agree on which cases remain reachable at all (a rule saved before the validator
 * existed, and an origin that has learned a target or operator this build has
 * not). An open string would make that agreement unverifiable, and a reason no
 * consumer can branch on is a log line, not a record.
 *
 * Every one of these is a fault in OUR configuration or OUR build, so none of
 * them may become a verdict about the target. A probe is a measurement: failing
 * the check on a malformed rule reports the customer's service as broken when the
 * rule is what is broken, which is the same reasoning `probe_refused` carries.
 */
export type AssertionSkipReason =
    /** The array element was not a rule object, or `target`/`operator` was not a string. */
    | "rule_malformed"

    /** The rule names a target this build does not implement. */
    | "unknown_target"

    /** The rule names an operator this build does not implement. */
    | "unknown_operator"

    /**
     * The operator needs a value and none arrived, or the value is not a kind the
     * target can be compared against.
     */
    | "value_invalid"

    /** A `header` rule arrived without a `name`. */
    | "header_name_missing"

    /**
     * The response carried no such header, and the operator cannot be answered
     * from a value that does not exist.
     *
     * This covers the NEGATED comparisons only. "Does not contain error" over a
     * header that was never sent is vacuously true, and certifying what was never
     * measured is a trap this repository has already paid for once. The positive
     * comparisons (`equals`, `contains`, `greater_than`, `less_than`,
     * `matches_regex`) FAIL instead, because the asserted content is demonstrably
     * not there and that is a measurement of the target, not of us; a header that
     * vanished is exactly what the operator wrote the rule to catch. Presence
     * itself is what `exists` / `not_exists` measure, so those two never skip.
     */
    | "header_absent"

    /** The pattern did not compile in this runtime. */
    | "regex_invalid"

    /**
     * The body exceeded the evaluation ceiling, so the only evidence available is
     * a prefix.
     *
     * A comparison over a prefix can be wrong in both directions (a match past the
     * cut reads as a non-match), and an unbounded body is where a stored pattern
     * becomes a ReDoS, so the rule is not run at all rather than run on part of
     * the evidence.
     */
    | "body_too_large";

/**
 * What happened to one rule.
 *
 * A discriminated union rather than a flat shape with a nullable reason: the flat
 * shape admits a failure carrying a skip reason and a skip carrying none, and
 * neither of those means anything. Here the reason exists exactly where it
 * applies, and is absent from the encoded outcome otherwise.
 *
 * `rule` echoes the rule verbatim so a stored outcome explains itself without a
 * join against a monitor that may have been edited since. It is null only for
 * `rule_malformed`, where there was no rule to echo.
 *
 * Read this by field and never by serialized form. It lands in a `jsonb` column,
 * where PostgreSQL preserves neither object key order nor duplicate keys, so a
 * hash or a string comparison over the encoded outcome is unstable by
 * construction and would pass on SQLite and fail on PostgreSQL. Array order IS
 * preserved, which is why a rule's position in {@link AssertionReport.results} is
 * its identity and no index is stored beside it.
 */
export type AssertionOutcome =
    | {
        verdict: "passed" | "failed";
        rule: AssertionRule;
        observed: AssertionObserved;
    }
    | {
        verdict: "skipped";
        rule: AssertionRule | null;
        observed: AssertionObserved;
        reason: AssertionSkipReason;
    };

/**
 * The result of evaluating a non-empty rule set.
 *
 * It exists only when at least one rule was configured; no rules yields null on
 * {@link CheckResultPayload.assertions} rather than an empty report, so `results`
 * is never empty. That invariant is documented rather than typed as
 * `[AssertionOutcome, ...AssertionOutcome[]]`, because TypeScript does not narrow
 * an array to a non-empty tuple from a length check and the evaluator would need
 * a cast to satisfy it, which costs more than the guarantee is worth.
 *
 * `passed` is decided HERE and never re-derived at the origin. All rules must pass
 * (the norm in every product surveyed) and a skipped rule is not a failure, so
 * re-deriving the verdict from `results` would mean a second implementation of
 * both of those rules in another language. A report whose every outcome is
 * `skipped` therefore carries `passed: true`: nothing was measured, so nothing
 * failed.
 *
 * `passed: false` implies the reading is `down`. The reverse does not hold: an
 * unexpected status code or an unreachable target is `down` with every assertion
 * passing.
 */
export type AssertionReport = {
    passed: boolean;
    results: AssertionOutcome[];
};

/**
 * Whether a thrown probe failure is the edge refusing to connect at all.
 *
 * Matched on the runtime's own wording, which is the only signal available;
 * there is no error code for it. Kept deliberately narrow: two independent
 * fragments must both appear, so an unrelated proxy error is not swallowed into
 * "not our problem".
 */
function isRefusedByEdge(error: unknown): boolean {
    if (!(error instanceof Error)) {
        return false;
    }

    const message = error.message.toLowerCase();

    return message.includes("proxy request failed")
        && message.includes("consider using fetch");
}

const BODY_PREVIEW_BYTES = 10_240;

/**
 * Body ceiling used only when the spec carries no {@link ProbeRequest.max_bytes}.
 *
 * The spec's value is the authority: it mirrors the origin's
 * `content-archive.max_bytes`, so raising that raises this. This fallback exists
 * for a spec from an origin older than the archive feature, and matches the
 * origin's own default so the two agree by construction.
 */
const CONTENT_MAX_BYTES_FALLBACK = 1_048_576;

export class RegionalProbe {
    async fetch(request: Request): Promise<Response> {
        if (request.method !== "POST") {
            return new Response("method not allowed", {
                status: 405,
            });
        }

        let probe: ProbeRequest;
        try {
            probe = (await request.json()) as ProbeRequest;
        } catch {
            return new Response("invalid json", {
                status: 400,
            });
        }

        const { result, colo } = await executeProbe(probe);
        return new Response(JSON.stringify(result), {
            status: 200,
            headers: {
                "content-type": "application/json",
                "x-probe-colo": colo,
            },
        });
    }
}

/**
 * Turn a thrown probe failure into a message an operator can act on.
 *
 * The raw message used to travel verbatim, and the Workers runtime reports any
 * connection-level failure (an unresolvable host, a refused port, a TLS
 * rejection) as `internal error; reference = <opaque id>`. That reached the UI
 * as the check's error, so a customer whose DNS was wrong read it as UPTIZM
 * being broken and had nothing to chase.
 *
 * The classification deliberately stops at what is actually known. Workers does
 * not expose which phase failed, so this never claims "DNS lookup failed": it
 * says the target could not be reached, names the target, and leaves any
 * message that IS specific (a TLS or certificate error) untouched.
 */
/**
 * The operator-facing message for a probe the edge refused.
 *
 * The runtime's own wording ends in "consider using fetch instead", which is
 * advice for whoever wrote this worker and means nothing to a customer looking at
 * a monitor. This says what they can actually change.
 */
function describeEdgeRefusal(probe: ProbeRequest): string {
    return `A TCP check cannot reach ${probeTarget(probe)}: the edge network `
        + "refuses a raw connection to a host it serves over HTTP. Monitor this "
        + "target with an HTTP check instead.";
}

export function describeProbeFailure(error: unknown, probe: ProbeRequest): string {
    const target = probeTarget(probe);

    // AbortSignal.timeout() rejects with a TimeoutError DOMException; some
    // runtimes surface a plain AbortError instead.
    const name = error instanceof Error ? error.name : "";
    if (name === "TimeoutError" || name === "AbortError") {
        return `No response within ${probe.timeout_seconds}s (${target})`;
    }

    if (!(error instanceof Error) || error.message.trim() === "") {
        return `Could not reach ${target}`;
    }

    // The opaque runtime wrapper carries no information beyond "the connection
    // did not happen", so it is replaced rather than appended to.
    if (/^internal error/i.test(error.message)) {
        return `Could not reach ${target}`;
    }

    return error.message;
}

/**
 * The host (and port, for TCP) a probe was aiming at, for the failure message.
 *
 * Only the host is named: an HTTP url can carry a token in its query string,
 * and this string is stored on the check and rendered in the UI.
 */
function probeTarget(probe: ProbeRequest): string {
    if (probe.type === "tcp") {
        try {
            const { hostname, port } = parseTcpTarget(probe.url);

            return `${hostname}:${port}`;
        } catch {
            return probe.url;
        }
    }

    try {
        return new URL(probe.url).host;
    } catch {
        return probe.url;
    }
}

async function executeProbe(
    probe: ProbeRequest,
): Promise<{ result: CheckResultPayload; colo: string }> {
    const checkedAt = new Date().toISOString();
    const start = Date.now();

    try {
        return probe.type === "tcp"
            ? await probeTcp(probe, checkedAt, start)
            : await probeHttp(probe, checkedAt, start);
    } catch (error: unknown) {
        const refused = isRefusedByEdge(error);

        return {
            result: {
                monitor_id: probe.monitor_id,
                probe_run_id: probe.probe_run_id,
                region: probe.region,
                checked_at: checkedAt,
                // A refused probe measured nothing, so it carries no verdict the
                // API should trust. `down` stays on the wire for older readers,
                // and `probe_refused` is what the API branches on.
                status: "down",
                status_code: null,
                response_ms: null,
                error_message: refused
                    ? describeEdgeRefusal(probe)
                    : describeProbeFailure(error, probe),
                timing: emptyTiming(),
                response_headers: {},
                response_body_preview: null,
                colo: "unknown",
                probe_refused: refused,
                content: null,
                content_type: null,
                content_truncated: false,
                // A probe that never got a response read nothing to assert
                // against, so no rule was evaluated. Reporting a failed
                // assertion here would blame the rules for a connection that
                // never happened.
                assertions: null,
            },
            colo: "unknown",
        };
    }
}

/**
 * HTTP(S) probe: issue the request at `probe.url`, classify the status code,
 * and capture TTFB / download timing plus a bounded body preview.
 */
async function probeHttp(
    probe: ProbeRequest,
    checkedAt: string,
    start: number,
): Promise<{ result: CheckResultPayload; colo: string }> {
    // 1. Fire the region-pinned probe and resolve the colo in parallel.
    const [response, colo] = await Promise.all([
        fetch(probe.url, {
            method: (probe.method ?? "GET").toUpperCase(),
            // Headers/method may be null when a monitor sets none; fetch()
            // rejects a null `headers`, so default to an empty object.
            // Credentials go on last so a monitor cannot accidentally shadow its
            // own Authorization header with a hand-written one.
            headers: { ...(probe.request_headers ?? {}), ...authHeaders(probe.auth_config) },
            body: probe.request_body ?? undefined,
            redirect: "manual",
            signal: AbortSignal.timeout(probe.timeout_seconds * 1000),
        }),
        resolveColo(),
    ]);
    const ttfbAt = Date.now();

    // 2. Drain the response body ONCE, up to the archive ceiling, and derive
    //    both the 10 KiB preview and the archivable content from that one read.
    const body = await readBody(response, probe);
    const downloadEnd = Date.now();

    // 3. DNS/connect/TLS stay zero: CF does not expose per-phase timing.
    const timing: TimingBreakdown = {
        ...emptyTiming(),
        ttfb_ms: ttfbAt - start,
        download_ms: downloadEnd - ttfbAt,
    };

    const responseMs = downloadEnd - start;
    const responseHeaders = extractHeaders(response.headers);

    // 4. Evaluate the monitor's own rules against this reading, and let a failed
    //    one set the verdict DOWN (D1). It COMPOSES with the status
    //    classification rather than replacing it: an unexpected code is already
    //    down, and a passing rule never upgrades a down to an up. A refused probe
    //    never reaches here at all, so an edge refusal stays a non-verdict.
    const subject: AssertionSubject = {
        statusCode: response.status,
        responseTimeMs: responseMs,
        headers: responseHeaders,
        body: body.assertable,
    };
    const assertions = evaluateAssertions(probe.assertion_rules, subject);
    const status = assertions?.passed === false
        ? "down"
        : determineStatus(response.status, probe.expected_status_code);

    return {
        result: {
            monitor_id: probe.monitor_id,
            probe_run_id: probe.probe_run_id,
            region: probe.region,
            checked_at: checkedAt,
            status,
            status_code: response.status,
            response_ms: responseMs,
            error_message: null,
            timing,
            response_headers: responseHeaders,
            response_body_preview: body.preview,
            colo,
            probe_refused: false,
            content: body.content,
            content_type: response.headers.get("content-type"),
            content_truncated: body.truncated,
            assertions,
        },
        colo,
    };
}

/**
 * TCP probe: open a socket to `host:port` and measure connect timing only.
 *
 * A TCP monitor targets a bare `host:port` (the backend rejects any other
 * shape), so there is no request to send or body to read: reaching the
 * `opened` state is the health signal. The socket is closed immediately after,
 * regardless of outcome. A connection that neither opens nor errors within the
 * monitor timeout is raced to a rejection, which the caller maps to `down`.
 */
async function probeTcp(
    probe: ProbeRequest,
    checkedAt: string,
    start: number,
): Promise<{ result: CheckResultPayload; colo: string }> {
    const { hostname, port } = parseTcpTarget(probe.url);
    const socket = connect(
        { hostname, port },
        { allowHalfOpen: false },
    );

    let colo: string;
    try {
        [, colo] = await Promise.all([
            withTimeout(
                socket.opened,
                probe.timeout_seconds * 1000,
                "tcp connect",
            ),
            resolveColo(),
        ]);
    } finally {
        // Fire and forget: closing a socket whose connect is still pending (the
        // timeout path) blocks on the OS connect attempt for its full ~75s SYN
        // timeout, which would stall the whole probe response. The isolate GCs
        // the socket after the request ends, so an un-awaited close is safe.
        void socket.close().catch(() => {});
    }

    const connectMs = Date.now() - start;
    const timing: TimingBreakdown = {
        ...emptyTiming(),
        connect_ms: connectMs,
    };

    return {
        result: {
            monitor_id: probe.monitor_id,
            probe_run_id: probe.probe_run_id,
            region: probe.region,
            checked_at: checkedAt,
            status: "up",
            status_code: null,
            response_ms: connectMs,
            error_message: null,
            timing,
            response_headers: {},
            response_body_preview: null,
            colo,
            probe_refused: false,
            // A TCP probe sends no request and reads no body: reaching `opened`
            // IS the health signal, so there is nothing to archive, and no
            // status code, header or body for a rule to be evaluated against.
            content: null,
            content_type: null,
            content_truncated: false,
            assertions: null,
        },
        colo,
    };
}

/**
 * Split a `host:port` TCP target into its parts.
 *
 * The backend validation guarantees a trailing `:port`, so the last colon is
 * the port separator (IPv6 literals are not a supported TCP target shape).
 */
function parseTcpTarget(target: string): { hostname: string; port: number } {
    const colon = target.lastIndexOf(":");
    return {
        hostname: target.slice(0, colon),
        port: Number(target.slice(colon + 1)),
    };
}

/**
 * Reject `promise` if it does not settle within `ms`, clearing the timer once
 * it does so a resolved socket never leaves a dangling timeout behind.
 */
function withTimeout<T>(promise: Promise<T>, ms: number, label: string): Promise<T> {
    let timer: ReturnType<typeof setTimeout>;
    const timeout = new Promise<never>((_, reject) => {
        timer = setTimeout(
            () => reject(new Error(`${label} timed out after ${ms}ms`)),
            ms,
        );
    });
    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

/**
 * Resolve the Cloudflare colo (IATA airport code) this DO is pinned to.
 *
 * `request.cf` is not populated on internal DO Requests, and outbound
 * `fetch(...).cf` is inconsistent across runtimes, so we read the
 * authoritative `cdn-cgi/trace` endpoint instead.
 */
async function resolveColo(): Promise<string> {
    try {
        const trace = await fetch("https://cloudflare.com/cdn-cgi/trace");
        const text = await trace.text();
        const match = /(?:^|\n)colo=([^\n]+)/.exec(text);
        return match?.[1]?.trim() ?? "unknown";
    } catch {
        return "unknown";
    }
}

function determineStatus(statusCode: number, expected: number): "up" | "down" | "degraded" {
    if (statusCode === expected) {
        return "up";
    }
    if (statusCode >= 200 && statusCode < 400) {
        return "degraded";
    }
    return "down";
}

/**
 * How far the body must be read for the monitor's rules to be answerable, in
 * BYTES, or 0 when no rule looks at the body.
 *
 * This exists because the archive decision must not decide the assertion
 * decision, and for a while it did. `contentCeiling` is 0 for any content type
 * off the spec's `allowed_content_types`, which collapsed the read to the 10 KiB
 * preview floor, left the stream unended, and skipped every body rule as
 * `body_too_large`. So a `body contains` rule on an `application/problem+json`,
 * `application/vnd.api+json` or `application/hal+json` endpoint, or on any
 * response that sent no content-type header at all, read `passed: true` forever
 * while never running. That is the same silent no-op the assertion work existed
 * to close, arriving through the archive's door instead, and it was worse than
 * the original: the lever is OUR `allowed_content_types` config, so an operator
 * reading `body_too_large` would go looking at `max_bytes` and find nothing
 * wrong.
 *
 * Reading further does NOT archive anything: `content` stays null for a
 * non-archivable type, so the extra bytes are evaluated and discarded.
 *
 * The ceiling is `CONTENT_MAX_BYTES_FALLBACK` rather than the origin's
 * `max_bytes`, deliberately. Coupling them is how the bug got here: an operator
 * lowering `max_bytes` to save archive space would silently stop their own
 * assertions from running. UTF-8 decodes to at most one character per byte, so a
 * body read whole under this byte ceiling can never exceed the evaluator's
 * `ASSERTION_BODY_MAX_CHARS`.
 */
function assertionReadCeiling(probe: ProbeRequest): number {
    const rules = probe.assertion_rules;

    if (!Array.isArray(rules)) {
        return 0;
    }

    // `target` is read off an unvalidated wire value, so this asks the question
    // the evaluator asks rather than trusting the declared type.
    const readsBody = rules.some(
        (rule) => (rule as { target?: unknown } | null)?.target === "body",
    );

    return readsBody ? CONTENT_MAX_BYTES_FALLBACK : 0;
}

type BodyRead = {
    /** The first {@link BODY_PREVIEW_BYTES} of the body, decoded. */
    preview: string | null;

    /** The archivable body, decoded, or null when it must not travel. */
    content: string | null;

    /** True when bytes past the content ceiling were discarded. */
    truncated: boolean;

    /**
     * The body an assertion is evaluated against, and whether it is a prefix of
     * what the target served.
     *
     * A THIRD view of the same single read rather than a duplicate of the other
     * two, because neither of them can answer the evaluator honestly. `content`
     * is null whenever the response's content type is off the spec's allowlist,
     * which would leave a body rule comparing against nothing and reporting a
     * healthy target as broken over OUR archive configuration. `truncated`
     * describes the archive slice, so it is false for that same non-archivable
     * body even though its read stopped at the preview floor, and a rule over an
     * unflagged prefix certifies a match it saw only part of the evidence for.
     * This pair is the fullest text the one read produced plus whether the stream
     * actually ended, which is what {@link AssertionSubject} needs and what only
     * this function knows.
     */
    assertable: {
        text: string;
        truncated: boolean;
    };
};

/**
 * Drain the response body ONCE and derive both the preview and the archivable
 * content from that single read.
 *
 * A stream can only be consumed once, and the 10 KiB preview column predates the
 * archive, so the two must come out of the same pass: reading twice would either
 * throw on a locked body or double the target's egress.
 *
 * A body whose content type is not on the spec's allowlist is read only as far
 * as the preview. There is nothing to archive, so pulling the remaining megabyte
 * across the edge would buy nothing, UNLESS the monitor asserts against the body:
 * see {@link assertionReadCeiling} for why that case has to override the archive
 * decision rather than inherit it.
 */
async function readBody(response: Response, probe: ProbeRequest): Promise<BodyRead> {
    if (response.body === null) {
        return {
            preview: null,
            content: null,
            truncated: false,
            // A response with no body is a COMPLETE reading of an empty body, so
            // `exists` over it is answerably false rather than unmeasurable.
            assertable: {
                text: "",
                truncated: false,
            },
        };
    }

    const archivable = contentTypeAllowed(
        response.headers.get("content-type"),
        probe.allowed_content_types ?? [],
    );
    const contentCeiling = archivable
        ? (probe.max_bytes ?? CONTENT_MAX_BYTES_FALLBACK)
        : 0;
    // The preview floor is independent of the archive ceiling: a deploy that
    // lowered `max_bytes` below 10 KiB must not quietly shrink the preview
    // column that existing consumers read.
    //
    // The assertion ceiling is independent of BOTH, and that independence is the
    // whole point of it. See {@link assertionReadCeiling}.
    const readLimit = Math.max(
        contentCeiling,
        BODY_PREVIEW_BYTES,
        assertionReadCeiling(probe),
    );

    const reader = response.body.getReader();
    const chunks: Uint8Array[] = [];
    let total = 0;
    let ended = false;

    while (total < readLimit) {
        const {
            done,
            value,
        } = await reader.read();
        if (done) {
            ended = true;
            break;
        }
        chunks.push(value);
        total += value.byteLength;
    }

    // A body that ends exactly ON the limit is indistinguishable from one that
    // continues past it until one more chunk is asked for. Without this an
    // exactly-1 MB page would be archived under a truncation flag it does not
    // deserve, and the API would treat a complete snapshot as a fragment.
    //
    // Gated on `readLimit` and not on `contentCeiling`, because the same ambiguity
    // decides `assertable.truncated`, which is a skip and not just a flag. With
    // `contentCeiling` here, a `max_bytes` set below 10 KiB left an archivable
    // body of exactly `readLimit` reading as a prefix, and every body rule
    // skipped. The archive's own flag below is unaffected either way: it tests
    // `total > contentCeiling` separately.
    if (!ended && total <= readLimit) {
        const {
            done,
        } = await reader.read();
        ended = done;
    }
    reader.cancel().catch(() => {});

    const merged = mergeChunks(chunks, Math.min(total, readLimit));

    return {
        preview: decodeUtf8(merged.subarray(0, Math.min(merged.byteLength, BODY_PREVIEW_BYTES))),
        content: archivable
            ? decodeUtf8(merged.subarray(0, Math.min(merged.byteLength, contentCeiling)))
            : null,
        truncated: archivable && (total > contentCeiling || !ended),
        assertable: {
            // Decoded only when a rule actually reads the body. Otherwise this is a
            // second full UTF-8 decode of up to 1 MiB, on top of `content` two lines
            // above, on a runtime metered by CPU, for a value nothing consults.
            text: assertionReadCeiling(probe) > 0 ? decodeUtf8(merged) : "",
            // `ended` is set only by a read that reported done, and every path
            // that can set it had `total` inside `readLimit`, so a stream that
            // never ended is exactly the case where `merged` is a prefix.
            truncated: !ended,
        },
    };
}

/**
 * Copy `chunks` into one buffer of exactly `size` bytes, dropping the overflow
 * of the chunk that crossed the limit.
 */
function mergeChunks(chunks: Uint8Array[], size: number): Uint8Array {
    const merged = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
        const copyLen = Math.min(chunk.byteLength, merged.byteLength - offset);
        merged.set(chunk.subarray(0, copyLen), offset);
        offset += copyLen;
        if (offset >= merged.byteLength) {
            break;
        }
    }
    return merged;
}

/**
 * Decode bytes as UTF-8 without failing on malformed input.
 *
 * Non-fatal on purpose: the ceiling cuts BYTES, so a multi-byte sequence split
 * at the boundary must become U+FFFD rather than throw away the whole body.
 */
function decodeUtf8(bytes: Uint8Array): string {
    return new TextDecoder("utf-8", {
        fatal: false,
        ignoreBOM: false,
    }).decode(bytes);
}

/**
 * Whether a response `Content-Type` may have its body returned.
 *
 * This MIRRORS `App\Support\Monitoring\ContentTypeAllowList::allows()` on the
 * API side, deliberately step for step: lowercase the raw header, cut at the
 * first `;` to drop parameters such as `charset=utf-8`, trim, then accept when
 * the result equals an exact rule or begins with a prefix rule (a rule ending in
 * `/`). A null or empty header is rejected, and so is every header when `rules`
 * is empty. Change one side and the other must move with it; the rules
 * themselves are never authored here, they arrive on the signed spec.
 */
function contentTypeAllowed(header: string | null, rules: string[]): boolean {
    if (header === null || header.trim() === "") {
        return false;
    }

    const mediaType = (header.split(";")[0] ?? "").trim().toLowerCase();
    if (mediaType === "") {
        return false;
    }

    for (const rule of rules) {
        if (rule.endsWith("/")) {
            if (mediaType.startsWith(rule)) {
                return true;
            }

            continue;
        }

        if (mediaType === rule) {
            return true;
        }
    }

    return false;
}

/**
 * A monitor's credential map, as the origin sends it.
 *
 * The `type` discriminates which of the other fields are populated; the Laravel
 * `StoreMonitorRequest` already enforces that pairing, so a field required by a
 * type is present whenever that type is set.
 */
export type AuthConfig = {
    type: "none" | "basic" | "bearer" | "api_key";
    username?: string | null;
    password?: string | null;
    token?: string | null;
    key?: string | null;
    header?: string | null;
};

/**
 * Turn a credential map into the headers the probe must carry.
 *
 * Until this existed the worker accepted `auth_config` on the signed spec and
 * never read it, so an authenticated target answered 401 or 403 and the monitor
 * was published DOWN: a false outage on exactly the monitors a customer cared
 * enough to configure.
 *
 * An unknown or incomplete map yields no headers rather than a throw. A probe is
 * a measurement, and failing the whole check on a malformed credential would
 * report the target as broken when the configuration is what is broken.
 */
export function authHeaders(auth: AuthConfig | null | undefined): Record<string, string> {
    if (!auth || typeof auth !== "object") {
        return {};
    }

    switch (auth.type) {
        case "basic":
            if (!auth.username || auth.password == null) {
                return {};
            }

            // btoa is Latin-1 only, so encode the pair as UTF-8 bytes first: a
            // non-ASCII password would otherwise throw and abort the probe.
            return {
                Authorization: `Basic ${base64(`${auth.username}:${auth.password}`)}`,
            };

        case "bearer":
            return auth.token ? { Authorization: `Bearer ${auth.token}` } : {};

        case "api_key":
            return auth.key && auth.header ? { [auth.header]: auth.key } : {};

        case "none":
            return {};

        default:
            // An unrecognised type means the origin shipped a case this worker
            // does not know yet. Send no credential rather than guess.
            return {};
    }
}

/**
 * UTF-8 safe base64, since `btoa` rejects any code point above U+00FF.
 */
function base64(value: string): string {
    const bytes = new TextEncoder().encode(value);
    let binary = "";

    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary);
}

function extractHeaders(headers: Headers): Record<string, string> {
    const result: Record<string, string> = {};
    headers.forEach((value, key) => {
        result[key] = value;
    });
    return result;
}
