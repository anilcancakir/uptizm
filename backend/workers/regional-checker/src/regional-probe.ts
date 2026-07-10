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

import {
    emptyTiming,
    type TimingBreakdown,
} from "./timing";

type ProbeRequest = {
    monitor_id: string;
    probe_run_id: string;
    region: string;
    type: "http" | "tcp";
    method: string;
    url: string;
    request_headers: Record<string, string>;
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
};

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

async function executeProbe(
    probe: ProbeRequest,
): Promise<{ result: CheckResultPayload; colo: string }> {
    const checkedAt = new Date().toISOString();
    const start = Date.now();

    try {
        // 1. Fire the region-pinned probe and resolve the colo in parallel.
        const [response, colo] = await Promise.all([
            fetch(probe.url, {
                method: probe.method.toUpperCase(),
                headers: probe.request_headers,
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
            },
            colo,
        };
    } catch (error: unknown) {
        return {
            result: {
                monitor_id: probe.monitor_id,
                probe_run_id: probe.probe_run_id,
                region: probe.region,
                checked_at: checkedAt,
                status: "down",
                status_code: null,
                response_ms: null,
                error_message: error instanceof Error ? error.message : "probe failed",
                timing: emptyTiming(),
                response_headers: {},
                response_body_preview: null,
            },
            colo: "unknown",
        };
    }
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
