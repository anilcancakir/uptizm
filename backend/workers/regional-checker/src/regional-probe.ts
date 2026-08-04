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
     * Response assertions the origin still evaluates rather than the edge.
     *
     * Declared so the field is not silently dropped from the signed spec; the
     * edge does not read it yet.
     */
    assertion_rules: unknown;

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

    const status = determineStatus(response.status, probe.expected_status_code);

    return {
        result: {
            monitor_id: probe.monitor_id,
            probe_run_id: probe.probe_run_id,
            region: probe.region,
            checked_at: checkedAt,
            status,
            status_code: response.status,
            response_ms: downloadEnd - start,
            error_message: null,
            timing,
            response_headers: extractHeaders(response.headers),
            response_body_preview: body.preview,
            colo,
            probe_refused: false,
            content: body.content,
            content_type: response.headers.get("content-type"),
            content_truncated: body.truncated,
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
            // IS the health signal, so there is nothing to archive.
            content: null,
            content_type: null,
            content_truncated: false,
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

type BodyRead = {
    /** The first {@link BODY_PREVIEW_BYTES} of the body, decoded. */
    preview: string | null;

    /** The archivable body, decoded, or null when it must not travel. */
    content: string | null;

    /** True when bytes past the content ceiling were discarded. */
    truncated: boolean;
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
 * across the edge would buy nothing.
 */
async function readBody(response: Response, probe: ProbeRequest): Promise<BodyRead> {
    if (response.body === null) {
        return {
            preview: null,
            content: null,
            truncated: false,
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
    const readLimit = Math.max(contentCeiling, BODY_PREVIEW_BYTES);

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

    // A body that ends exactly ON the ceiling is indistinguishable from one that
    // continues past it until one more chunk is asked for. Without this an
    // exactly-1 MB page would be archived under a truncation flag it does not
    // deserve, and the API would treat a complete snapshot as a fragment.
    if (!ended && total <= contentCeiling) {
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
