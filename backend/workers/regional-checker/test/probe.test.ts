/**
 * `RegionalProbe` is the only thing in this system that actually measures a
 * customer's service, and its whole output is one payload the API turns into a
 * verdict. Two production failures of that surface are already on record, and both
 * were invisible from the payload alone:
 *
 * 1. The edge accepted a monitor's `auth_config` on the signed spec and never sent
 *    it (fixed in PR #2, `dd2d402`). An authenticated target answered 401 and the
 *    monitor was published DOWN, on exactly the monitors a customer cared enough to
 *    configure. Every payload looked perfectly healthy in shape; the defect was in
 *    the REQUEST, which nothing observed.
 * 2. `assertion_rules` arrived and were read by nobody, so a monitor whose body
 *    assertion was violated was published `up`. The evaluator that fixed it is
 *    unit-tested in `assertions.test.ts`; what is tested HERE is that `probeHttp`
 *    feeds it the reading it actually took and lets a failure reach `status`.
 *
 * So the organising rule of this suite: assert on the request the probe SENT, not
 * only on the payload it returned. `vi.spyOn(globalThis, "fetch")` is the
 * fixture-proven way to see it (`fetchMock` was removed from the pool in 0.20.0
 * and is not an option). Every case below was checked against one question, the
 * same one `assertions.test.ts` uses: would it still pass if the probe returned a
 * hardcoded healthy payload and sent nothing? The credential cases read the sent
 * headers, the refusal cases pin a flag AND its negative twin, the assertion cases
 * pin a `down` on a 200, and the TCP cases are paired with a closed port. None of
 * them survives that stub.
 *
 * Two structural notes:
 *
 * - The DO is reached through `runInDurableObject` and `env` from
 *   `cloudflare:workers`, so the code under test is the deployed class inside real
 *   `workerd`, with the real `Request`/`Response` and the real `connect()`. `SELF`
 *   and `defineWorkersConfig` are removed APIs; see `vitest.config.ts`.
 * - `ProbeRequest` and `CheckResultPayload` are module-private in
 *   `regional-probe.ts` and are mirrored below rather than imported. Exporting them
 *   to satisfy a test would widen the module's public surface for no runtime
 *   reason, and the wire shape is what this suite is pinning anyway: the origin
 *   builds the spec in PHP and reads the payload in PHP, so a local mirror is the
 *   same act of transcription the other side already performs. The two types that
 *   ARE imported (`AuthConfig`, `AssertionRule`) are exported because production
 *   code outside the module already needs them.
 */

import { runInDurableObject } from "cloudflare:test";
import type { DurableObject } from "cloudflare:workers";
import { env } from "cloudflare:workers";
import { afterEach, describe, expect, inject, it, vi } from "vitest";

import type {
    AssertionReport,
    AssertionRule,
    AuthConfig,
    RegionalProbe,
} from "../src/regional-probe";

/**
 * The DO class as a Durable Object.
 *
 * `RegionalProbe` now extends `DurableObject<Env>` (the Sentry instrumentation
 * wrapper's signature requires it), so it already carries the branded shape
 * `runInDurableObject` and `DurableObjectNamespace<T>` want. The intersection is
 * kept because it costs nothing and states the same truth either way: this class
 * IS the DO behind that binding. It was load-bearing when the class stood alone,
 * and it is what keeps this file honest if the base class is ever dropped again.
 */
type ProbeInstance = RegionalProbe & DurableObject;

declare global {
    namespace Cloudflare {
        interface Env {
            RegionalProbe: DurableObjectNamespace<ProbeInstance>;
        }
    }
}

/** The signed spec, as `RelayClient` on the origin builds it. */
type ProbeSpec = {
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
    auth_config: AuthConfig | null;
    assertion_rules: AssertionRule[] | null;
    max_bytes?: number;
    allowed_content_types?: string[];
    user_agent?: string | null;
    follow_redirects?: boolean;
};

/** The payload, as `CheckResult::fromWorkerPayload()` on the origin reads it. */
type ProbeResult = {
    monitor_id: string;
    probe_run_id: string;
    region: string;
    checked_at: string;
    status: "up" | "down" | "degraded";
    status_code: number | null;
    response_ms: number | null;
    error_message: string | null;
    timing: Record<string, number>;
    response_headers: Record<string, string>;
    response_body_preview: string | null;
    colo: string;
    probe_refused: boolean;
    content: string | null;
    content_type: string | null;
    content_truncated: boolean;
    assertions: AssertionReport | null;
};

/**
 * `resolveColo()` reads this endpoint on every probe, HTTP and TCP alike, so the
 * fixture has to answer it or the suite reaches the real internet and a machine
 * with no egress reports `colo: "unknown"` instead of failing honestly.
 */
const TRACE_URL = "https://cloudflare.com/cdn-cgi/trace";

const TARGET_URL = "https://target.example/health";
const TARGET_HOST = "target.example";

const JSON_BODY = "{\"status\":\"ok\",\"queue\":42}";

/**
 * The preview floor, mirroring `BODY_PREVIEW_BYTES` in `src/regional-probe.ts`.
 *
 * Hardcoded rather than exported from the source, because it is the boundary the
 * body-read cases straddle: a test that imported the constant would follow a
 * later edit that moved the floor and stay green through the very change it
 * exists to notice.
 */
const BODY_PREVIEW_BYTES = 10_240;

function spec(overrides: Partial<ProbeSpec> = {}): ProbeSpec {
    return {
        monitor_id: "01JMONITORID",
        probe_run_id: "01JPROBERUNID",
        region: "eu-west",
        type: "http",
        // Lowercase deliberately: the origin's `HttpMethod` enum serialises
        // lowercase and the probe is what uppercases it.
        method: "get",
        url: TARGET_URL,
        request_headers: null,
        request_body: null,
        timeout_seconds: 10,
        expected_status_code: 200,
        auth_config: null,
        assertion_rules: null,
        allowed_content_types: [
            "application/json",
        ],
        user_agent: null,
        follow_redirects: false,
        ...overrides,
    };
}

/** A JSON response with the shape most cases want. */
function jsonResponse(body = JSON_BODY, status = 200): Response {
    return new Response(body, {
        status,
        headers: {
            "content-type": "application/json",
            "x-cache": "HIT",
        },
    });
}

type Responder = (request: Request) => Response | Promise<Response>;

/**
 * The outbound request, flattened.
 *
 * The body is read where the request is BUILT rather than in the case that asserts
 * on it, because a request body is an I/O object owned by the Durable Object's
 * context and reading it from the test's context throws "Cannot perform I/O on
 * behalf of a different Durable Object" (measured). `Headers` carries no I/O, so it
 * travels as-is.
 */
type SentRequest = {
    method: string;
    url: string;
    headers: Headers;
    body: string;
    redirect: Request["redirect"];
};

/**
 * Refuse to answer a probe request no case set up an answer for.
 *
 * The default rather than a benign empty response, because a case that reaches the
 * network by accident should fail loudly instead of quietly measuring something
 * else. TCP cases use it as-is: they send no HTTP request at all, so any call
 * arriving here other than the trace is a defect.
 */
const NO_FIXTURE: Responder = (request) => {
    throw new Error(`no fixture for an outbound request to ${request.url}`);
};

/**
 * Replace `fetch` for the duration of one case and hand back the request the probe
 * sent.
 *
 * This is the whole point of the suite: the auth defect was invisible in the
 * payload and visible only here. `globalThis.fetch` is the seam because the probe
 * calls the global unqualified, so the DO running inside workerd sees the spy.
 */
function interceptFetch(respond: Responder = NO_FIXTURE): () => SentRequest {
    let captured: SentRequest | null = null;

    vi.spyOn(globalThis, "fetch").mockImplementation(async (input, init) => {
        const request = new Request(input, init);
        if (request.url === TRACE_URL) {
            return new Response("fl=1f2a3\nh=cloudflare.com\nip=203.0.113.7\ncolo=TST\n");
        }

        captured = {
            method: request.method,
            url: request.url,
            headers: request.headers,
            body: await request.clone().text(),
            // The redirect MODE, not the redirect behaviour: this spy replaces
            // `fetch` outright, so the runtime never gets to follow anything.
            // What the probe controls is the instruction, and that is what a
            // test at this layer can honestly assert.
            redirect: request.redirect,
        };

        return respond(request);
    });

    return () => {
        if (captured === null) {
            throw new Error("the probe sent no outbound request");
        }

        return captured;
    };
}

/** Every header value on a request, for asserting a credential is nowhere in it. */
function headerValues(request: SentRequest): string[] {
    const values: string[] = [];
    request.headers.forEach((value) => {
        values.push(value);
    });

    return values;
}

/**
 * Send one request to the DO, as the edge worker does.
 *
 * The `Request` is constructed INSIDE the callback and not handed in from the
 * outside, which is not a style choice: `runInDurableObject` delivers the callback
 * to the object's own context, and a request built in the test context arrives
 * there with an unreadable body, so every probe answered `400 invalid json`
 * (measured while writing this suite).
 */
async function runRaw(method: string, body?: string): Promise<Response> {
    const stub = env.RegionalProbe.get(env.RegionalProbe.idFromName("eu-west"));

    return await runInDurableObject(
        stub,
        (instance) => instance.fetch(new Request("https://probe/execute", {
            method,
            headers: {
                "content-type": "application/json",
            },
            body,
        })),
    );
}

async function runProbe(probe: ProbeSpec): Promise<{ result: ProbeResult; response: Response }> {
    const response = await runRaw("POST", JSON.stringify(probe));

    return {
        result: await response.json() as ProbeResult,
        response,
    };
}

/**
 * A credential shape the wire can carry and {@link AuthConfig} forbids.
 *
 * The origin decrypts `auth_config` and forwards it; an origin deployed ahead of
 * this worker can name a `type` this build has never heard of, which is the case
 * the `default` branch of `authHeaders` exists for.
 */
function fromTheWire(value: unknown): AuthConfig {
    return value as AuthConfig;
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe("RegionalProbe.fetch: the DO's own contract", () => {
    it("refuses anything but POST", async () => {
        const response = await runRaw("GET");

        expect(response.status).toBe(405);
    });

    it("refuses a body that is not JSON", async () => {
        // 400 rather than a probe against a spec it guessed at: the relay is
        // machine-to-machine, so a malformed spec is a bug on the origin and
        // answering it with a measurement would hide that.
        const response = await runRaw("POST", "not json at all");

        expect(response.status).toBe(400);
    });

    it("echoes the idempotency key and the requested region", async () => {
        // `probe_run_id` is the dedup key of the whole round trip: the origin
        // matches the stored check on it, so dropping or regenerating it here would
        // let one probe persist twice.
        interceptFetch(() => jsonResponse());

        const { result } = await runProbe(spec({
            probe_run_id: "01JDISTINCTRUN",
            region: "us-west",
        }));

        expect(result.probe_run_id).toBe("01JDISTINCTRUN");
        expect(result.region).toBe("us-west");
        expect(result.monitor_id).toBe("01JMONITORID");
    });

    it("reports the colo it ran from in the body as well as the header", async () => {
        // The colo used to travel only in `x-probe-colo`, which the API never read,
        // so a stored check's `region` was an echo of what the caller asked for
        // rather than evidence of where the probe ran. Both places are asserted
        // because the header is the older contract and the body is the one that is
        // persisted.
        interceptFetch(() => jsonResponse());

        const { result, response } = await runProbe(spec());

        expect(result.colo).toBe("TST");
        expect(response.headers.get("x-probe-colo")).toBe("TST");
    });
});

describe("the credential the probe actually sends", () => {
    it("sends no Authorization header when the monitor configured none", async () => {
        // The unauthenticated monitor, which is most of them: nothing may be added
        // to a request that asked for nothing. `sent()` throws when no request was
        // made at all, so this is not satisfied by a probe that never fired.
        const sent = interceptFetch(() => jsonResponse());

        await runProbe(spec({
            auth_config: null,
        }));

        expect(sent().headers.get("authorization")).toBeNull();
    });

    it("sends no Authorization header for the explicit `none` type", async () => {
        // The same intent in the other shape the origin can send it: a monitor whose
        // credential was cleared keeps `type: "none"` rather than losing the key.
        const sent = interceptFetch(() => jsonResponse());

        await runProbe(spec({
            auth_config: {
                type: "none",
            },
        }));

        expect(sent().headers.get("authorization")).toBeNull();
    });

    it("sends basic credentials as base64 of `username:password`", async () => {
        // The expected value is a LITERAL, computed independently
        // (`base64.b64encode(b"admin:s3cr3t")`), not by re-running the
        // implementation's own encoder in the test. Deriving the expectation from
        // the same algorithm as the code certifies the algorithm rather than
        // checking it, which this repository has already been bitten by.
        const sent = interceptFetch(() => jsonResponse());

        await runProbe(spec({
            auth_config: {
                type: "basic",
                username: "admin",
                password: "s3cr3t",
            },
        }));

        expect(sent().headers.get("authorization")).toBe("Basic YWRtaW46czNjcjN0");
    });

    it("encodes a non-ASCII basic password as UTF-8 bytes", async () => {
        // Two distinct wrong answers are ruled out here, which is why the credential
        // is deliberately Turkish. `btoa` alone THROWS above U+00FF, so the naive
        // version does not mangle the header, it aborts the probe and publishes a
        // healthy target as unreachable. An encoder that walked code UNITS instead of
        // UTF-8 bytes would not throw and would send `52FrMXI6X2lmcmUtqQ==`, a
        // credential the target rejects. The expectation is the literal
        // `base64.b64encode("çakır:şifre-Ω".encode("utf-8"))`, computed outside this
        // runtime, so it agrees with neither.
        const sent = interceptFetch(() => jsonResponse());

        await runProbe(spec({
            auth_config: {
                type: "basic",
                username: "çakır",
                password: "şifre-Ω",
            },
        }));

        expect(sent().headers.get("authorization")).toBe("Basic w6dha8SxcjrFn2lmcmUtzqk=");
    });

    it("treats an empty basic password as a credential, not as a missing one", async () => {
        // `auth.password == null` and deliberately not `!auth.password`: an empty
        // password is a legal credential (plenty of appliances issue one), and
        // refusing to send it would be the PR #2 defect again, narrower. The
        // literal decodes to `admin:`.
        const sent = interceptFetch(() => jsonResponse());

        await runProbe(spec({
            auth_config: {
                type: "basic",
                username: "admin",
                password: "",
            },
        }));

        expect(sent().headers.get("authorization")).toBe("Basic YWRtaW46");
    });

    it("sends a bearer token as `Bearer <token>`", async () => {
        // The scheme prefix is the whole of it: a target handed a bare token answers
        // 401 exactly as it does for no token at all, which is PR #2's symptom with a
        // credential that WAS sent, so the payload would look identical.
        const sent = interceptFetch(() => jsonResponse());

        await runProbe(spec({
            auth_config: {
                type: "bearer",
                token: "vault-issued-token",
            },
        }));

        expect(sent().headers.get("authorization")).toBe("Bearer vault-issued-token");
    });

    it("sends an api_key under the header the monitor named", async () => {
        // The header name is operator-supplied, so the case that matters is that
        // the key lands under THAT name and not under a hardcoded one.
        const sent = interceptFetch(() => jsonResponse());

        await runProbe(spec({
            auth_config: {
                type: "api_key",
                key: "k-9f2b",
                header: "X-Tenant-Key",
            },
        }));

        expect(sent().headers.get("x-tenant-key")).toBe("k-9f2b");
        expect(sent().headers.get("authorization")).toBeNull();
    });

    it("sends no credential at all when the map is incomplete, and still probes", async () => {
        // D2's precedent, and the half that is easy to get wrong in the other
        // direction: a malformed credential must send NOTHING rather than a broken
        // header, and it must not abort the probe either. Both are asserted, since
        // "sends none" is satisfied by a probe that threw before it sent anything,
        // and that would publish a target that is up as unreachable.
        const secret = "SHOULD-NEVER-BE-SENT";
        const incomplete: Array<{ label: string; auth: AuthConfig }> = [
            {
                label: "basic without a password",
                auth: {
                    type: "basic",
                    username: "admin",
                },
            },
            {
                label: "basic without a username",
                auth: {
                    type: "basic",
                    password: secret,
                },
            },
            {
                label: "bearer without a token",
                auth: {
                    type: "bearer",
                },
            },
            {
                label: "api_key without a header name",
                auth: {
                    type: "api_key",
                    key: secret,
                },
            },
            {
                label: "api_key without a key",
                auth: {
                    type: "api_key",
                    header: "X-Tenant-Key",
                },
            },
            {
                label: "a type this build has never heard of",
                auth: fromTheWire({
                    type: "mutual_tls",
                    token: secret,
                }),
            },
        ];

        for (const shape of incomplete) {
            const sent = interceptFetch(() => jsonResponse());
            const { result } = await runProbe(spec({
                auth_config: shape.auth,
            }));

            expect(sent().headers.get("authorization"), shape.label).toBeNull();
            expect(
                headerValues(sent()).filter((value) => value.includes(secret)),
                shape.label,
            ).toEqual([]);
            expect(result.status, shape.label).toBe("up");
            expect(result.error_message, shape.label).toBeNull();

            vi.restoreAllMocks();
        }
    });

    it("lets the credential win over a hand-written header of the same name", async () => {
        // The merge direction is a DECISION, not an accident, and the plan's phrasing
        // ("credentials do not shadow a monitor's own request_headers") reads as its
        // opposite. What is right is what the code does: `auth_config` is the field
        // the panel presents as authentication and the origin decrypts for this
        // purpose, so a stale hand-written `Authorization` left in `request_headers`
        // must not defeat it. Letting the free-text header win would be PR #2's
        // defect wearing a different hat, a configured credential that never
        // reaches the target.
        const sent = interceptFetch(() => jsonResponse());

        await runProbe(spec({
            request_headers: {
                Authorization: "Bearer stale-hand-written",
            },
            auth_config: {
                type: "bearer",
                token: "vault-issued-token",
            },
        }));

        expect(sent().headers.get("authorization")).toBe("Bearer vault-issued-token");
    });

    it("keeps every other header the monitor set", async () => {
        // The other half of that merge: winning on a collision must not mean
        // replacing the map. A monitor's `Accept` or tracing header is often what
        // makes the target answer at all.
        const sent = interceptFetch(() => jsonResponse());

        await runProbe(spec({
            request_headers: {
                Accept: "application/vnd.api+json",
                "X-Trace-Id": "trace-77",
            },
            auth_config: {
                type: "bearer",
                token: "vault-issued-token",
            },
        }));

        expect(sent().headers.get("accept")).toBe("application/vnd.api+json");
        expect(sent().headers.get("x-trace-id")).toBe("trace-77");
        expect(sent().headers.get("authorization")).toBe("Bearer vault-issued-token");
    });

    it("forwards the method and the body the monitor configured", async () => {
        // The origin's `HttpMethod` enum serialises lowercase (`"post"`), and the
        // target has to receive an uppercase verb, so both halves of that contract
        // are pinned here. Only ONE of them is a guard, and saying which matters:
        // deleting `.toUpperCase()` from `probeHttp` leaves this suite 33/33 green,
        // because workerd's `Request` constructor normalises every verb measured
        // (`get`, `post`, `head`, `patch`, `delete`, `put`, `options` all came back
        // uppercase without it). So the method assertion documents the wire contract
        // and the BODY assertion is what fails if the request is built wrong; the
        // explicit uppercase in the source is redundant in this runtime rather than
        // load-bearing, and it was left alone because it costs nothing and does not
        // depend on that normalisation staying as it is.
        const sent = interceptFetch(() => jsonResponse());

        await runProbe(spec({
            method: "post",
            request_body: "{\"ping\":1}",
        }));

        expect(sent().method).toBe("POST");
        expect(sent().body).toBe("{\"ping\":1}");
    });

    it("defaults to GET when the monitor set no method", async () => {
        // `method` is nullable on the wire and `fetch` rejects a null one, so the
        // default is the difference between a probe and a thrown request.
        const sent = interceptFetch(() => jsonResponse());

        await runProbe(spec({
            method: null,
        }));

        expect(sent().method).toBe("GET");
    });
});

describe("an edge refusal is never a verdict about the target", () => {
    /**
     * A refusal as the runtime words it.
     *
     * A fixture and not the real thing, because the real thing cannot be reached
     * from here: a TCP probe at `cloudflare.com:443` was run against this very
     * harness and it CONNECTED (`status: "up"`, `connect_ms: 255`). Local `workerd`
     * dials the address directly, so the refusal is a property of Cloudflare's
     * production edge network and there is no local input that produces it. That is
     * a documented negative, not a shortcut.
     *
     * So what these cases pin is the CLASSIFICATION rather than the sentence: the
     * wording belongs to workerd and a runtime update may reword it, while the two
     * independent fragments it is matched on are ours to keep honest. The case below
     * that half-matches is the other half of that contract.
     */
    function edgeRefusal(): Error {
        return new Error(
            "proxy request failed, cannot connect to the specified address. "
            + "If the hostname is proxied by Cloudflare, consider using fetch instead.",
        );
    }

    it("flags a refusal and says what the operator can change", async () => {
        // The API branches on this flag BEFORE anything else
        // (`CheckPersistenceService::persist` step 0): a refused probe never becomes
        // a check, so it cannot advance `consecutive_fails`, cross
        // `incident_threshold`, or page a responder for a service that is up. The
        // `down` on the wire is deliberate legacy for an older reader and is not the
        // discriminator; the flag is.
        interceptFetch(() => {
            throw edgeRefusal();
        });

        const { result } = await runProbe(spec());

        expect(result.probe_refused).toBe(true);
        expect(result.status).toBe("down");
        expect(result.status_code).toBeNull();
        expect(result.response_ms).toBeNull();

        // The runtime's own advice ("consider using fetch") is written for whoever
        // wrote this worker and means nothing to a customer, so it must not reach
        // the UI. The replacement names the target and the action.
        expect(result.error_message).toContain(TARGET_HOST);
        expect(result.error_message?.toLowerCase()).not.toContain("consider using fetch");
        expect(result.error_message?.toLowerCase()).not.toContain("proxy request failed");
    });

    it("treats a bot challenge as a refusal rather than an outage", async () => {
        // Measured on production: openai.com and claude.ai answer this relay with
        // 403, `cf-mitigated: challenge` and a 10 KB interactive challenge page,
        // continuously, while serving browsers perfectly well. Two "is down"
        // incidents stayed open for seven and a half hours over it.
        //
        // A challenge is not a verdict about the service. It is the target's edge
        // declining to let this probe measure anything, which is precisely what
        // `probe_refused` already means, and no request header can change it: the
        // page wants JavaScript executed.
        interceptFetch(() => new Response("<!DOCTYPE html><html>challenge</html>", {
            status: 403,
            headers: {
                "cf-mitigated": "challenge",
                "content-type": "text/html; charset=UTF-8",
            },
        }));

        const { result } = await runProbe(spec());

        expect(result.probe_refused).toBe(true);
        expect(result.status).toBe("down");

        // The operator gets the one fact they can act on, and the header is quoted
        // because it is what makes this a measurement rather than a guess.
        expect(result.error_message).toContain(TARGET_HOST);
        expect(result.error_message).toContain("cf-mitigated");
    });

    it("identifies itself with the user agent the origin publishes", async () => {
        // `resources/legal/bot.en.md` tells every operator that both clients
        // "identify themselves with the same string on every request" and that
        // blocking that string is a supported way to stop us. Only the feed
        // reader was doing it; the availability check, which is the larger of
        // the two by a wide margin, sent whatever the runtime defaulted to. The
        // published page has to be true, and honouring a block (the challenge
        // branch above) is worth nothing if there is nothing to block.
        //
        // It travels on the signed spec rather than being a worker constant for
        // the reason `max_bytes` does: the origin renders that page from the
        // same config value, and a second copy here is how the two drift.
        const sent = interceptFetch(() => new Response("ok", { status: 200 }));

        await runProbe(spec({ user_agent: "UptizmBot/1.0 (+https://uptizm.com/bot)" }));

        expect(sent().headers.get("user-agent")).toBe("UptizmBot/1.0 (+https://uptizm.com/bot)");
    });

    it("lets a monitor's own user agent win whatever case it is written in", async () => {
        // A monitor that declares its own User-Agent is describing the request it
        // wants made, and ours is a default, not an override.
        //
        // The casing is the whole test. Header names are case-insensitive on the
        // wire but a plain object spread is not, so merging `User-Agent` over
        // `user-agent` produces BOTH keys and `Headers` joins them with a comma:
        // the target would receive `UptizmBot/1.0, AcmeChecker/2` and match
        // neither. Going through `Headers.set()` is what makes the override real.
        const sent = interceptFetch(() => new Response("ok", { status: 200 }));

        await runProbe(spec({
            user_agent: "UptizmBot/1.0 (+https://uptizm.com/bot)",
            request_headers: { "User-Agent": "AcmeChecker/2" },
        }));

        expect(sent().headers.get("user-agent")).toBe("AcmeChecker/2");
    });

    it("sends no user agent when the spec carries none", async () => {
        // An origin deployed behind this worker does not send the field, and
        // inventing a string here would put a second source of truth beside the
        // published page. Absent is absent.
        const sent = interceptFetch(() => new Response("ok", { status: 200 }));

        await runProbe(spec({ user_agent: null }));

        expect(sent().headers.get("user-agent")).toBeNull();
    });

    it("stops at a redirect unless the monitor asked to follow it", async () => {
        // The default this probe has always had, and the reason it is a default:
        // a login page answering 302 instead of 200 is a regression, and
        // following it would publish the login screen as health. `bot.en.md`
        // also promises a third-party operator that the availability check reads
        // no other page, and the origin is what keeps a catalog monitor off this
        // flag; here the rule is simply "honour what the spec says".
        const sent = interceptFetch(() => new Response(null, {
            status: 307,
            headers: { location: "https://target.example/fr" },
        }));

        const { result } = await runProbe(spec({ follow_redirects: false }));

        expect(sent().redirect).toBe("manual");
        expect(result.status_code).toBe(307);
        expect(result.status).toBe("degraded");
    });

    it("asks the runtime to follow when the monitor said to", async () => {
        // Measured on production: stripe.com answers the probe with a 307 to a
        // language-specific path, so a monitor of the homepage read `degraded`
        // forever while the service was working. Following is opt-in per monitor
        // because neither answer is right for every one of them.
        //
        // Asserted on the REQUEST rather than on a followed response, and that
        // is not a weaker test, it is the only honest one here: the spy replaces
        // `fetch`, so workerd never performs the follow. The instruction is what
        // this probe owns; performing it is the runtime's job.
        const sent = interceptFetch(() => new Response("ok", { status: 200 }));

        await runProbe(spec({ follow_redirects: true }));

        expect(sent().redirect).toBe("follow");
    });

    it("keeps a plain 403 a real outage", async () => {
        // The expensive direction to get wrong. An ordinary 403 is the target
        // answering, and laundering every one of them into "not our problem" would
        // silently stop a genuine authorization failure from ever paging anyone.
        // Only the challenge header earns the refusal.
        interceptFetch(() => new Response("forbidden", { status: 403 }));

        const { result } = await runProbe(spec());

        expect(result.probe_refused).toBe(false);
        expect(result.status).toBe("down");
        expect(result.status_code).toBe(403);
    });

    it("records no assertion outcome for a probe that never got a response", async () => {
        // Rules were configured and nothing was read, so blaming them would be
        // reporting a measurement that did not happen. Null is "not evaluated", which
        // is a different column state from "every rule passed".
        interceptFetch(() => {
            throw edgeRefusal();
        });

        const { result } = await runProbe(spec({
            assertion_rules: [
                {
                    target: "body",
                    operator: "contains",
                    value: "\"status\":\"ok\"",
                },
            ],
        }));

        expect(result.probe_refused).toBe(true);
        expect(result.assertions).toBeNull();
    });

    it("does not flag a failure that only half-matches the refusal wording", async () => {
        // The classification requires TWO independent fragments precisely so an
        // unrelated proxy error is not laundered into "not our problem". Getting
        // this wrong in this direction is the expensive one: a real outage that
        // mentions a proxy would silently stop becoming a check, and nobody would
        // ever be paged for it.
        interceptFetch(() => {
            throw new Error("proxy request failed: upstream returned 502 from the customer's CDN");
        });

        const { result } = await runProbe(spec());

        expect(result.probe_refused).toBe(false);
        expect(result.status).toBe("down");
    });

    it("names the timeout it actually waited for", async () => {
        // `AbortSignal.timeout()` rejects with a `TimeoutError`, and some runtimes
        // surface a plain `AbortError` for the same event, so both names are covered:
        // a monitor whose target is merely slow must read as a timeout the operator
        // can raise, not as a host that could not be reached. The seconds come from
        // the spec rather than from a literal, since a message naming the wrong
        // timeout sends someone to change a setting that is already correct.
        for (const name of ["TimeoutError", "AbortError"]) {
            const timedOut = new Error("The operation was aborted due to timeout");
            timedOut.name = name;
            interceptFetch(() => {
                throw timedOut;
            });

            const { result } = await runProbe(spec({
                timeout_seconds: 7,
            }));

            expect(result.probe_refused, name).toBe(false);
            expect(result.error_message, name).toBe(`No response within 7s (${TARGET_HOST})`);

            vi.restoreAllMocks();
        }
    });

    it("replaces the runtime's opaque wrapper with the target it could not reach", async () => {
        // Workers reports every connection-level failure (bad DNS, refused port, a
        // TLS rejection) as `internal error; reference = <opaque id>`. That reached
        // the UI verbatim once, so a customer whose DNS was wrong read it as UPTIZM
        // being broken and had nothing to chase.
        interceptFetch(() => {
            throw new Error("internal error; reference = 9c1f0d2ab3e4");
        });

        const { result } = await runProbe(spec());

        expect(result.error_message).toBe(`Could not reach ${TARGET_HOST}`);
        expect(result.error_message).not.toContain("reference");
        expect(result.probe_refused).toBe(false);
    });

    it("keeps a URL's query string out of the failure message", async () => {
        // The message is stored on the check and rendered in the UI, and an HTTP url
        // can carry a token in its query string, so only the host is ever named.
        interceptFetch(() => {
            throw new Error("internal error; reference = 9c1f0d2ab3e4");
        });

        const { result } = await runProbe(spec({
            url: "https://target.example/health?api_token=super-secret",
        }));

        expect(result.error_message).toBe(`Could not reach ${TARGET_HOST}`);
        expect(result.error_message).not.toContain("super-secret");
    });
});

describe("assertions decide the verdict on a response that was received", () => {
    const bodyContainsOk: AssertionRule = {
        target: "body",
        operator: "contains",
        value: "\"status\":\"ok\"",
    };

    it("evaluates the rules against the reading and reports the outcome", async () => {
        // The wiring, not the evaluator: `probeHttp` has to hand over the status it
        // received, the elapsed ms it measured, the headers it extracted and the body
        // it read. One rule per target proves all four arrived. The latency bound is
        // deliberately loose, because a wall-clock assertion is how a suite starts
        // failing at 3am.
        interceptFetch(() => jsonResponse());

        const { result } = await runProbe(spec({
            assertion_rules: [
                {
                    target: "status_code",
                    operator: "equals",
                    value: 200,
                },
                {
                    target: "response_time_ms",
                    operator: "less_than",
                    value: 60_000,
                },
                {
                    target: "header",
                    operator: "equals",
                    name: "Content-Type",
                    value: "application/json",
                },
                bodyContainsOk,
            ],
        }));

        expect(result.assertions?.results.map((outcome) => outcome.verdict)).toEqual([
            "passed",
            "passed",
            "passed",
            "passed",
        ]);
        expect(result.assertions?.passed).toBe(true);
        expect(result.status).toBe("up");
    });

    it("publishes a 200 with a failed assertion as DOWN", async () => {
        // D1, and the single most important case in this file. The status code is
        // the expected one, so the classification says `up`; the assertion is what
        // makes it `down`. No surveyed product has an assertion-failed severity, so
        // there is no third state to look for here.
        interceptFetch(() => jsonResponse("{\"status\":\"degraded\"}"));

        const { result } = await runProbe(spec({
            assertion_rules: [
                bodyContainsOk,
            ],
        }));

        expect(result.status_code).toBe(200);
        expect(result.assertions?.passed).toBe(false);
        expect(result.assertions?.results[0].verdict).toBe("failed");
        expect(result.status).toBe("down");
    });

    it("does not let a passing assertion upgrade a status the code already condemned", async () => {
        // Assertions COMPOSE with the classification, they do not replace it. A
        // monitor expecting 200 that receives a 503 is down whatever its rules say,
        // and a 301 stays degraded: a rule set that could launder either would turn
        // assertions into a way to silence a monitor.
        interceptFetch(() => jsonResponse(JSON_BODY, 503));

        const condemned = await runProbe(spec({
            assertion_rules: [
                bodyContainsOk,
            ],
        }));

        expect(condemned.result.assertions?.passed).toBe(true);
        expect(condemned.result.status).toBe("down");

        vi.restoreAllMocks();
        interceptFetch(() => jsonResponse(JSON_BODY, 301));

        const redirected = await runProbe(spec({
            assertion_rules: [
                bodyContainsOk,
            ],
        }));

        expect(redirected.result.assertions?.passed).toBe(true);
        expect(redirected.result.status).toBe("degraded");
    });

    it("records a rule it could not evaluate without failing the check", async () => {
        // A rule this build cannot read is OUR configuration, so it is recorded and
        // the target is reported on its own merits. The verdict is asserted as well
        // as the report, so a stub that skips everything and calls it passed does
        // not satisfy the case.
        interceptFetch(() => jsonResponse());

        const { result } = await runProbe(spec({
            assertion_rules: [
                {
                    target: "body",
                    operator: "matches_regex",
                    value: "([unclosed",
                },
            ],
        }));

        const outcome = result.assertions?.results[0];

        expect(outcome?.verdict).toBe("skipped");
        expect(outcome?.verdict === "skipped" ? outcome.reason : null).toBe("regex_invalid");
        expect(result.assertions?.passed).toBe(true);
        expect(result.status).toBe("up");
    });

    it("reports no assertion report at all for a monitor with no rules", async () => {
        // D4. `monitor_checks.assertions_passed` is nullable so that "not evaluated"
        // is representable; an empty report here would record "every assertion
        // passed" for a monitor that asserts nothing.
        interceptFetch(() => jsonResponse());

        const { result } = await runProbe(spec({
            assertion_rules: null,
        }));

        expect(result.assertions).toBeNull();
        expect(result.status).toBe("up");
    });

    it("asserts against a body the content archive is not allowed to keep", async () => {
        // The subtle one. `content` is null whenever the response's type is off the
        // spec's allowlist, so a body rule reading THAT field would compare against
        // nothing and report a healthy target as broken over our own archive
        // configuration. The evaluator gets a third view of the same single read,
        // and this case is what holds those two apart.
        interceptFetch(() => new Response("<html>status: ok</html>", {
            headers: {
                "content-type": "text/html; charset=utf-8",
            },
        }));

        const { result } = await runProbe(spec({
            allowed_content_types: [
                "application/json",
            ],
            assertion_rules: [
                {
                    target: "body",
                    operator: "contains",
                    value: "status: ok",
                },
            ],
        }));

        expect(result.content).toBeNull();
        expect(result.content_type).toBe("text/html; charset=utf-8");
        expect(result.assertions?.results[0].verdict).toBe("passed");
        expect(result.status).toBe("up");
    });

    it("asserts against a body the archive refuses AND that is larger than the preview floor", async () => {
        // The case above passes on a 23-byte body, where the stream ends inside the
        // 10 KiB preview and the rule runs by accident of size. This is the one that
        // caught a real defect: with the archive refusing the type, `contentCeiling`
        // was 0, the read stopped at the preview floor, the stream never ended, and
        // every body rule skipped as `body_too_large` while the check published
        // `up`. Every `application/problem+json`, `application/vnd.api+json` and
        // `application/hal+json` endpoint over 10 KiB was affected, and the recorded
        // reason pointed the operator at `max_bytes` when the lever was
        // `allowed_content_types`. The needle sits PAST the floor on purpose: a fix
        // that only widened the flag without widening the read would still pass a
        // needle inside the first 10 KiB.
        const needle = "\"detail\":\"queue drained\"";
        const oversized = "x".repeat(BODY_PREVIEW_BYTES + 4_096) + needle;

        interceptFetch(() => new Response(oversized, {
            headers: {
                "content-type": "application/problem+json",
            },
        }));

        const { result } = await runProbe(spec({
            allowed_content_types: [
                "application/json",
            ],
            assertion_rules: [
                {
                    target: "body",
                    operator: "contains",
                    value: needle,
                },
            ],
        }));

        expect(result.content).toBeNull();
        expect(result.assertions?.results[0].verdict).toBe("passed");
        expect(result.assertions?.passed).toBe(true);
        expect(result.status).toBe("up");
    });

    it("does not read past the preview floor for a monitor with no body rule", async () => {
        // The other half of the fix, so "read further" cannot become "read further
        // always". A monitor that asserts nothing, or asserts only on the status
        // code, must not pull an extra megabyte across the edge for a value nothing
        // reads. `response_body_preview` is the observable proof of how much was
        // kept.
        interceptFetch(() => new Response("y".repeat(BODY_PREVIEW_BYTES + 4_096), {
            headers: {
                "content-type": "application/problem+json",
            },
        }));

        const { result } = await runProbe(spec({
            allowed_content_types: [
                "application/json",
            ],
            assertion_rules: [
                {
                    target: "status_code",
                    operator: "equals",
                    value: 200,
                },
            ],
        }));

        expect(result.content).toBeNull();
        expect(result.response_body_preview).toHaveLength(BODY_PREVIEW_BYTES);
        expect(result.assertions?.results[0].verdict).toBe("passed");
    });

    it("returns the decoded body for a type the archive does allow", async () => {
        // The other side of the case above, so "content is null" cannot be satisfied
        // by a probe that never fills it. The preview and the archived content come
        // out of ONE read of a stream that can only be consumed once, and the headers
        // are the map the evaluator reads, so all three are checked together.
        interceptFetch(() => jsonResponse());

        const { result } = await runProbe(spec());

        expect(result.content).toBe(JSON_BODY);
        expect(result.content_truncated).toBe(false);
        expect(result.response_body_preview).toBe(JSON_BODY);
        expect(result.response_headers["x-cache"]).toBe("HIT");
    });
});

describe("the TCP probe, against a real listener", () => {
    /**
     * There is no protocol-level mock for `cloudflare:sockets` `connect()`, so these
     * cases open real sockets from real `workerd` to a real `net.createServer`
     * started in `test/global-setup.ts`. That is the upstream pattern (the
     * hyperdrive fixture) and it is the only one available; a "mocked TCP probe"
     * does not exist to be written.
     */
    function tcpSpec(port: number, overrides: Partial<ProbeSpec> = {}): ProbeSpec {
        return spec({
            type: "tcp",
            url: `127.0.0.1:${port}`,
            ...overrides,
        });
    }

    it("reports a reachable port as up and times the connect", async () => {
        interceptFetch();

        const { result } = await runProbe(tcpSpec(inject("tcpListenerPort")));

        expect(result.error_message).toBeNull();
        expect(result.probe_refused).toBe(false);
        expect(result.status).toBe("up");

        // `connect_ms` is deliberately never compared against a number: this is a
        // real socket on a real machine, and a wall-clock expectation is how a suite
        // starts failing on a loaded CI box. What IS pinned is everything around it,
        // which is where a defect would actually live: connect is the only phase a
        // TCP probe can measure, so the other four stay zero and no sixth key appears,
        // and `response_ms` must BE that connect time rather than some other elapsed
        // value.
        expect(result.timing).toEqual({
            dns_ms: 0,
            connect_ms: result.timing.connect_ms,
            tls_ms: 0,
            ttfb_ms: 0,
            download_ms: 0,
        });
        expect(result.response_ms).toBe(result.timing.connect_ms);
    });

    it("resolves its colo on the TCP path too", async () => {
        // `resolveColo()` is awaited beside the connect rather than after it, and a
        // TCP probe that reported `unknown` would leave its stored region unverifiable
        // in exactly the way the colo field was added to fix.
        interceptFetch();

        const { result } = await runProbe(tcpSpec(inject("tcpListenerPort")));

        expect(result.colo).toBe("TST");
    });

    it("carries nothing an HTTP reading would carry", async () => {
        // Reaching `opened` IS the health signal: no request is sent and no body is
        // read, so there is no status code, no header, no content, and nothing for a
        // rule to be evaluated against. The rules are supplied here deliberately: a
        // TCP probe that reported them as PASSED would be certifying a measurement
        // it never took.
        interceptFetch();

        const { result } = await runProbe(tcpSpec(inject("tcpListenerPort"), {
            assertion_rules: [
                {
                    target: "body",
                    operator: "contains",
                    value: "\"status\":\"ok\"",
                },
            ],
        }));

        expect(result.status_code).toBeNull();
        expect(result.response_headers).toEqual({});
        expect(result.content).toBeNull();
        expect(result.content_type).toBeNull();
        expect(result.response_body_preview).toBeNull();
        expect(result.assertions).toBeNull();
    });

    it("reports a port nothing listens on as down, and not as our refusal", async () => {
        // The negative twin of the case above, and what makes the fixture listener
        // load-bearing rather than decorative: without it, "up" would be the answer
        // to any port at all. Port 1 is reserved and unused, so the connect is
        // refused by the local kernel, which is a fact about the TARGET and must
        // therefore become a verdict, unlike an edge refusal.
        interceptFetch();

        const { result } = await runProbe(tcpSpec(1, {
            timeout_seconds: 5,
        }));

        expect(result.status).toBe("down");
        expect(result.probe_refused).toBe(false);
        expect(result.error_message).not.toBeNull();
        expect(result.assertions).toBeNull();
    });
});
