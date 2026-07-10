/**
 * Timing breakdown for a probe. Cloudflare's Workers runtime doesn't
 * expose per-phase TCP/TLS timings to user code the way Navigation
 * Timing does in browsers, so we approximate:
 *
 *   - `dns_ms` / `connect_ms` / `tls_ms` are best-effort zeros (CF
 *     multiplexes these across its edge); the API consumer treats a
 *     zero as "unknown" rather than "instant".
 *   - `ttfb_ms` is the wall-clock interval from fetch start until the
 *     first response byte is available.
 *   - `download_ms` is the interval spent draining the response body.
 *
 * Future work: adopt CF's experimental `performance.measureUserAgentSpecificMemory`
 * / timing APIs when they expose per-phase TCP info.
 */

export type TimingBreakdown = {
    dns_ms: number;
    connect_ms: number;
    tls_ms: number;
    ttfb_ms: number;
    download_ms: number;
};

export function emptyTiming(): TimingBreakdown {
    return {
        dns_ms: 0,
        connect_ms: 0,
        tls_ms: 0,
        ttfb_ms: 0,
        download_ms: 0,
    };
}
