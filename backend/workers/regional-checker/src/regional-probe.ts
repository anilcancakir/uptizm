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
    auth_config: unknown;
    assertion_rules: unknown;
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
            headers: probe.request_headers ?? {},
            body: probe.request_body ?? undefined,
            redirect: "manual",
            signal: AbortSignal.timeout(probe.timeout_seconds * 1000),
        }),
        resolveColo(),
    ]);
    const ttfbAt = Date.now();

    // 2. Drain up to 10 KiB of the response body for the preview.
    const preview = await readBodyPreview(response);
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
            response_body_preview: preview,
            colo,
            probe_refused: false,
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

async function readBodyPreview(response: Response): Promise<string | null> {
    if (response.body === null) {
        return null;
    }
    const reader = response.body.getReader();
    const chunks: Uint8Array[] = [];
    let total = 0;

    while (total < BODY_PREVIEW_BYTES) {
        const {
            done,
            value,
        } = await reader.read();
        if (done) {
            break;
        }
        chunks.push(value);
        total += value.byteLength;
    }
    reader.cancel().catch(() => {});

    const merged = new Uint8Array(Math.min(total, BODY_PREVIEW_BYTES));
    let offset = 0;
    for (const chunk of chunks) {
        const copyLen = Math.min(chunk.byteLength, merged.byteLength - offset);
        merged.set(chunk.subarray(0, copyLen), offset);
        offset += copyLen;
        if (offset >= merged.byteLength) {
            break;
        }
    }
    return new TextDecoder("utf-8", {
        fatal: false,
        ignoreBOM: false,
    }).decode(merged);
}

function extractHeaders(headers: Headers): Record<string, string> {
    const result: Record<string, string> = {};
    headers.forEach((value, key) => {
        result[key] = value;
    });
    return result;
}
