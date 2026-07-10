/**
 * Uptizm regional checker worker (multi-region via Durable Objects).
 *
 * Endpoints:
 *   - GET  /health  → heartbeat (Laravel polls it to confirm the relay is up)
 *   - POST /run     → HMAC-verified probe dispatch, synchronous result
 *
 * The relay is SYNCHRONOUS: Laravel POSTs the signed spec to `/run` and
 * reads the {@link CheckResultPayload} straight from the response body.
 * There is no callback path.
 *
 * The worker itself runs at whichever Cloudflare colo is closest to the
 * caller. To execute the outbound probe from the *target* geography it
 * forwards the request to a {@link RegionalProbe} Durable Object keyed
 * by region name and pinned via `locationHint`. The DO returns the
 * payload inline, echoing the request's `probe_run_id` so Laravel can
 * dedup on it.
 */

import {
    verifySignature,
} from "./hmac";

export {
    RegionalProbe,
} from "./regional-probe";

type Env = {
    RELAY_SECRET: string;
    RegionalProbe: DurableObjectNamespace;
};

/**
 * Region → Cloudflare Durable Object location hint. Hints are coarse
 * (9 geographic buckets), so Asia-Pacific collapses to a single `ap`
 * region for now. When Cloudflare exposes finer-grained hints or we
 * move to a paid plan, split APAC here.
 */
const REGION_TO_HINT: Record<string, DurableObjectLocationHint> = {
    "us-east": "enam",
    "us-west": "wnam",
    "eu-west": "weur",
    "eu-central": "eeur",
    "ap": "apac",
};

export default {
    async fetch(request: Request, env: Env): Promise<Response> {
        const url = new URL(request.url);

        if (request.method === "GET" && url.pathname === "/health") {
            return jsonResponse({
                ok: true,
                regions: Object.keys(REGION_TO_HINT),
            });
        }

        if (request.method === "POST" && url.pathname === "/run") {
            return await handleRun(request, env);
        }

        return new Response("not found", {
            status: 404,
        });
    },
};

async function handleRun(request: Request, env: Env): Promise<Response> {
    // 1. Pull signature headers and body.
    const signature = request.headers.get("X-Relay-Signature") ?? "";
    const timestamp = request.headers.get("X-Relay-Timestamp") ?? "";
    const body = await request.text();

    // 2. Verify HMAC over `${timestamp}.${body}`.
    const valid = await verifySignature(body, timestamp, signature, env.RELAY_SECRET);
    if (!valid) {
        return new Response("invalid signature", {
            status: 401,
        });
    }

    // 3. Parse just enough to route (the DO re-parses the full payload).
    let region: string;
    try {
        const parsed = JSON.parse(body) as { region?: unknown };
        if (typeof parsed.region !== "string") {
            return new Response("missing region", {
                status: 400,
            });
        }
        region = parsed.region;
    } catch {
        return new Response("invalid json", {
            status: 400,
        });
    }

    const locationHint = REGION_TO_HINT[region];
    if (locationHint === undefined) {
        return new Response(`unknown region: ${region}`, {
            status: 400,
        });
    }

    // 4. Forward to the region-pinned Durable Object and relay its result.
    const id = env.RegionalProbe.idFromName(region);
    const stub = env.RegionalProbe.get(id, {
        locationHint,
    });
    return await stub.fetch("https://probe/execute", {
        method: "POST",
        headers: {
            "content-type": "application/json",
        },
        body,
    });
}

function jsonResponse(payload: unknown): Response {
    return new Response(JSON.stringify(payload), {
        status: 200,
        headers: {
            "content-type": "application/json",
        },
    });
}
