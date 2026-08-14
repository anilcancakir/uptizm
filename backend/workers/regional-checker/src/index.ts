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
    getTraceData,
    instrumentDurableObjectWithSentry,
    withSentry,
} from "@sentry/cloudflare";

import {
    verifySignature,
} from "./hmac";

import {
    RegionalProbe as RegionalProbeClass,
} from "./regional-probe";

import type {
    Env,
} from "./env";

/**
 * How often a probe invocation becomes a Sentry transaction.
 *
 * Sized against the thing that actually calls this worker: one invocation per
 * monitor per region per interval, and the shortest interval a plan sells is 5
 * seconds (`config/plans.php`). A hundred monitors on a 30 second interval
 * across five regions is already ~1000 invocations a minute, so a rate anyone
 * would call "low" in a normal web app is still a flood here. At 0.001 that is
 * roughly one transaction a minute, which is enough to watch the shape of edge
 * latency without paying for a sample of every probe.
 *
 * It matches the rate `config/sentry.php` gives the `checks` queue on the
 * origin, deliberately: the two halves measure the same event from opposite
 * ends, and sampling them at different rates would mean the pair is almost
 * never captured together.
 *
 * ERRORS ARE NOT SAMPLED. This governs transactions only; an exception is
 * always sent. That distinction is what makes the low rate safe.
 */
const TRACES_SAMPLE_RATE = 0.001;

/**
 * Sentry options shared by the router and the Durable Object.
 *
 * Both wrappers need the same client, and taking a callback rather than a
 * literal is what lets them read `env`, which is the only place a Worker can
 * get configuration from.
 *
 * An ABSENT `SENTRY_DSN` disables the SDK rather than failing, and that is a
 * requirement rather than a convenience: `wrangler dev` and the vitest pool
 * both run without one, and a worker that refused to boot without a DSN would
 * take the whole test suite with it. `wrangler.toml` sets it for the deployed
 * script only.
 *
 * WHAT DOES NOT REACH SENTRY FROM HERE, and it is the point of the whole file:
 * a target that is down. `executeProbe()` catches every probe failure and turns
 * it into a `CheckResultPayload`, so a refused connection, a TLS error or a 500
 * from a customer's site never throws and never becomes an issue. This client
 * reports OUR failures: a malformed spec, a signature verification that blew
 * up, a bug in the assertion evaluator. Keeping that line intact is what stops
 * an outage on a customer's site from paging us instead of them.
 */
function sentryOptions(env: Env): Record<string, unknown> {
    return {
        dsn: env.SENTRY_DSN,
        release: env.SENTRY_RELEASE,
        environment: env.SENTRY_ENVIRONMENT ?? "production",
        tracesSampleRate: TRACES_SAMPLE_RATE,
        // The probe spec carries an operator's credential (`auth_config`) and
        // the response carries a customer's body. Neither belongs in an event,
        // and `sendDefaultPii` is what would start attaching request data.
        sendDefaultPii: false,
    };
}

/**
 * The region-pinned probe, wrapped so a throw inside it becomes an issue.
 *
 * `instrumentDurableObjectWithSentry` wraps `fetch`, `alarm`, the WebSocket
 * handlers and any RPC method. This class only implements `fetch`.
 *
 * ONE OPEN GAP TO RESPECT (getsentry/sentry-javascript#22545): the client is
 * disposed when the invocation ends, so an error thrown by a detached
 * continuation, a promise nobody awaited and nobody passed to `ctx.waitUntil`,
 * is dropped silently. Nothing here starts one today: `executeProbe()` is
 * awaited before the response is built. Any future fire-and-forget work in this
 * class has to go through `ctx.waitUntil` or it will report nothing at all,
 * which reads exactly like "no errors".
 */
export const RegionalProbe = instrumentDurableObjectWithSentry(
    sentryOptions,
    RegionalProbeClass,
);

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

export default withSentry(sentryOptions, {
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
} satisfies ExportedHandler<Env>);

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

    // Carry the trace into the Durable Object.
    //
    // Both ends of this hop are already instrumented and BOTH read an incoming
    // `sentry-trace` header (the DO's `fetch` is wrapped by the same
    // `wrapRequestHandler` the router uses), but a Durable Object stub call is
    // a fresh request whose headers are the ones written right here. Without
    // this spread the probe shows up as an unrelated root trace, and the chain
    // that starts at the Laravel job breaks at its most interesting hop: the
    // one that actually measures the customer's target.
    //
    // `getTraceData()` answers an empty object when the SDK is disabled, so
    // this is a no-op in `wrangler dev` and in the test pool rather than a
    // branch that needs guarding.
    return await stub.fetch("https://probe/execute", {
        method: "POST",
        headers: {
            "content-type": "application/json",
            ...getTraceData(),
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
