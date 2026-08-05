/**
 * `verifySignature` guards the only entry point Laravel has into this worker: a
 * wrong answer here means either a forged spec gets executed (an attacker can
 * make the on-call page for a target they chose) or a legitimate relay call is
 * refused (a real outage never gets probed). Both failure directions are worth
 * a dedicated case, not just the happy path.
 *
 * `verifySignature` takes `secret` as a plain parameter, so this suite needs no
 * `RELAY_SECRET` binding at all: every case below signs with a literal test
 * secret. Proven independent of `.dev.vars` by temporarily renaming it and
 * re-running `npm test` (see the step report); the suite must stay green
 * whether or not that gitignored file exists, because CI's worker job never
 * creates it.
 */

import { describe, expect, it, vi } from "vitest";
import { verifySignature } from "../src/hmac";

const SECRET = "test-secret-do-not-use-in-production";
const TTL_SECONDS = 300;

/**
 * Signs `${timestamp}.${body}` exactly as the production `verifySignature`
 * does, so a test can produce a signature that is known-correct without
 * duplicating any assertion logic from `src/hmac.ts`.
 */
async function sign(body: string, timestamp: string, secret: string): Promise<string> {
    const key = await crypto.subtle.importKey(
        "raw",
        new TextEncoder().encode(secret),
        {
            name: "HMAC",
            hash: "SHA-256",
        },
        false,
        [
            "sign",
        ],
    );
    const bytes = await crypto.subtle.sign(
        "HMAC",
        key,
        new TextEncoder().encode(`${timestamp}.${body}`),
    );
    return Array.from(new Uint8Array(bytes))
        .map((b) => b.toString(16).padStart(2, "0"))
        .join("");
}

describe("verifySignature", () => {
    it("accepts a correctly signed body and timestamp", async () => {
        const body = "{\"monitor_id\":1}";
        const timestamp = String(Math.floor(Date.now() / 1000));
        const signature = await sign(body, timestamp, SECRET);

        await expect(verifySignature(body, timestamp, signature, SECRET)).resolves.toBe(true);
    });

    it("rejects a signature produced with the wrong secret", async () => {
        // Catches the case where Laravel and the worker disagree on RELAY_SECRET
        // (a rotated secret on one side only); the request must not be trusted.
        const body = "{\"monitor_id\":1}";
        const timestamp = String(Math.floor(Date.now() / 1000));
        const signature = await sign(body, timestamp, "a-different-secret");

        await expect(verifySignature(body, timestamp, signature, SECRET)).resolves.toBe(false);
    });

    it("rejects when the body is tampered with after signing", async () => {
        // A signature computed over the original body must not validate a
        // request whose body was altered in transit (or by a proxy) afterward.
        const originalBody = "{\"monitor_id\":1}";
        const tamperedBody = "{\"monitor_id\":2}";
        const timestamp = String(Math.floor(Date.now() / 1000));
        const signature = await sign(originalBody, timestamp, SECRET);

        await expect(verifySignature(tamperedBody, timestamp, signature, SECRET)).resolves.toBe(false);
    });

    it("rejects when the timestamp is tampered with after signing", async () => {
        // The timestamp is part of the signed payload, so swapping it for a
        // fresher one (to slip a stale, otherwise-expired request past the
        // replay check) must invalidate the signature, not just re-time it.
        const body = "{\"monitor_id\":1}";
        const originalTimestamp = String(Math.floor(Date.now() / 1000));
        const rewrittenTimestamp = String(Math.floor(Date.now() / 1000) + 1);
        const signature = await sign(body, originalTimestamp, SECRET);

        await expect(verifySignature(body, rewrittenTimestamp, signature, SECRET)).resolves.toBe(false);
    });

    it("rejects a timestamp outside the replay window", async () => {
        // Derived from the same clock the code reads (`vi.setSystemTime`), not a
        // hardcoded epoch: this repo has already shipped a test that was red for
        // one hour a day from a hardcoded wall-clock assumption (340d985).
        const now = new Date("2026-08-05T12:00:00Z");
        vi.useFakeTimers();
        vi.setSystemTime(now);
        try {
            const body = "{\"monitor_id\":1}";
            const staleTimestamp = String(Math.floor(now.getTime() / 1000) - (TTL_SECONDS + 1));
            const signature = await sign(body, staleTimestamp, SECRET);

            await expect(verifySignature(body, staleTimestamp, signature, SECRET)).resolves.toBe(false);
        } finally {
            vi.useRealTimers();
        }
    });

    it("accepts a timestamp exactly at the replay window boundary (300s)", async () => {
        // `Math.abs(now - parsed) > TTL_SECONDS` is a strict `>`, so a diff of
        // exactly 300 is still inside the window. This is the inclusive side of
        // the boundary; the previous case covers 301, the first excluded value.
        const now = new Date("2026-08-05T12:00:00Z");
        vi.useFakeTimers();
        vi.setSystemTime(now);
        try {
            const body = "{\"monitor_id\":1}";
            const boundaryTimestamp = String(Math.floor(now.getTime() / 1000) - TTL_SECONDS);
            const signature = await sign(body, boundaryTimestamp, SECRET);

            await expect(verifySignature(body, boundaryTimestamp, signature, SECRET)).resolves.toBe(true);
        } finally {
            vi.useRealTimers();
        }
    });

    it("rejects a non-numeric timestamp", async () => {
        // `Number.parseInt` on a wholly non-numeric string yields NaN, which
        // `Number.isFinite` catches. A partially numeric string like "12abc"
        // parses to 12 and passes that guard instead, so it is NOT an honest
        // test of the non-numeric branch; it would only exercise the replay
        // window check on an ancient epoch, which the boundary cases above
        // already cover.
        const body = "{\"monitor_id\":1}";
        const timestamp = "not-a-timestamp";
        const signature = await sign(body, timestamp, SECRET);

        await expect(verifySignature(body, timestamp, signature, SECRET)).resolves.toBe(false);
    });

    it("rejects a signature of the wrong length", async () => {
        // `timingSafeEqual` returns early on a length mismatch; a truncated
        // signature must not fall through to a byte-by-byte compare that could
        // short-circuit on the shared prefix.
        const body = "{\"monitor_id\":1}";
        const timestamp = String(Math.floor(Date.now() / 1000));
        const signature = await sign(body, timestamp, SECRET);
        const truncated = signature.slice(0, -2);

        await expect(verifySignature(body, timestamp, truncated, SECRET)).resolves.toBe(false);
    });

    it("rejects an otherwise-correct signature presented in uppercase hex", async () => {
        // `bytesToHex` always emits lowercase, and the compare is a plain
        // char-code diff, so an uppercase-hex signature (same bytes, different
        // case) must not validate even though it is byte-for-byte identical.
        const body = "{\"monitor_id\":1}";
        const timestamp = String(Math.floor(Date.now() / 1000));
        const signature = await sign(body, timestamp, SECRET);

        await expect(verifySignature(body, timestamp, signature.toUpperCase(), SECRET)).resolves.toBe(false);
    });

    it("rejects when one byte of a correct signature is flipped", async () => {
        // The step's mandatory QA scenario: mutate a single hex character of an
        // otherwise-valid signature and confirm the mismatch is caught. A
        // suite where this case would pass with `verifySignature` returning
        // `true` unconditionally is not testing anything.
        const body = "{\"monitor_id\":1}";
        const timestamp = String(Math.floor(Date.now() / 1000));
        const signature = await sign(body, timestamp, SECRET);
        const flippedChar = signature[0] === "0" ? "1" : "0";
        const flipped = flippedChar + signature.slice(1);

        await expect(verifySignature(body, timestamp, flipped, SECRET)).resolves.toBe(false);
    });
});
