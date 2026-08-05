/**
 * `evaluateAssertions` decides whether a response the probe actually received
 * satisfies the rules its operator wrote. Until it exists, the edge accepts
 * `assertion_rules` on every signed spec and reads none of them, so a monitor
 * whose body assertion is violated is published `up`: the exact silence a
 * customer configured the rule to break.
 *
 * The two failure directions are worth equal weight and the suite is arranged
 * around them. A missed failure is a real outage nobody is paged for. A wrong
 * failure is worse than it looks: every skip reason in `AssertionSkipReason`
 * names a fault in OUR configuration or OUR build, so turning one into a
 * verdict reports the customer's service as broken when the rule is what is
 * broken, and it does so on every check until someone edits the rule. That is
 * the same reasoning `probe_refused` already carries.
 *
 * THIS SUITE FIXES THE API, because it is written before the implementation and
 * the implementation must match it. Two decisions in it are not obvious:
 *
 * 1. The subject is a plain reading, `AssertionSubject`, and `probeHttp` can
 *    build it from values it already has: `response.status`, the elapsed ms it
 *    already computes, `extractHeaders(response.headers)`, and the single body
 *    read. Headers arrive as `Record<string, string>` rather than as a `Headers`
 *    instance because that is what `CheckResultPayload.response_headers` and
 *    `extractHeaders` already produce; HTTP header names are case-insensitive,
 *    so the case-folding has to live in the evaluator and is asserted here in
 *    both directions.
 * 2. The body arrives as `{ text, truncated }` and the evaluator owns a
 *    character ceiling of its own (`ASSERTION_BODY_MAX_CHARS`). Both halves are
 *    needed and neither substitutes for the other: `truncated` is the only
 *    signal that a SHORT body is a prefix (an origin may set `max_bytes` to a
 *    few KiB, in which case the probe read a fragment of a megabyte page), while
 *    the ceiling is the guard that keeps a stored, operator-supplied pattern
 *    from ever running against an unbounded string on a runtime with a CPU
 *    budget and no RE2.
 *
 * The comparison contract these cases encode, stated once so it is not inferred
 * from forty assertions:
 *
 *   - `greater_than` / `less_than` compare numerically, always.
 *   - `contains` / `not_contains` / `matches_regex` / `not_matches_regex`
 *     compare as text, always; a numeric target contributes its decimal string,
 *     which is what makes an operator's `200|302` regex work as the alternation
 *     the operator set deliberately does not offer.
 *   - `equals` / `not_equals` follow the KIND OF THE RULE'S VALUE: a JSON number
 *     compares numerically, a string compares as text.
 *   - `exists` / `not_exists` measure presence and read no value.
 *   - Numeric mode reads a text target through `Number(...)`; text that is not a
 *     number is `value_invalid`, not a failure, because an ordering over a
 *     non-number is unanswerable rather than false, and a rule aimed at the
 *     wrong endpoint would otherwise page someone on every single check.
 *   - Numeric equality is NEVER `===`. UptimeRobot's own worked example
 *     equality-compares a price of `0.000003`, and a served float carries the
 *     double's full decimal expansion.
 *
 * Every case below was checked against one question: would it still pass if
 * `evaluateAssertions` returned `{ passed: true, results: [one skipped
 * outcome] }` for every input? Three would, and all three are report-level
 * `passed: true` cases that are honest regression guards rather than
 * discriminators; each of them therefore also asserts the per-outcome verdicts,
 * except `every outcome skipped` where that shape IS the assertion. The rest
 * pin a `passed` or `failed` verdict, a specific skip reason, or a null report,
 * none of which that stub produces.
 */

import { describe, expect, it } from "vitest";

import {
    ASSERTION_BODY_MAX_CHARS,
    type AssertionSubject,
    evaluateAssertions,
} from "../src/assertions";
import type {
    AssertionOutcome,
    AssertionRule,
} from "../src/regional-probe";

/**
 * The bound on a recorded `observed` value for a text target.
 *
 * Hardcoded rather than imported because it is a CONTRACT, not a tuning knob:
 * `AssertionObserved` fixes it at 256 characters, since the outcome rides inside
 * `assertion_results` through the Redis `processing` queue, where `content` is
 * excluded for precisely this reason. Importing the implementation's own
 * constant would let a later edit widen the bound and keep the test green.
 */
const OBSERVED_EXCERPT_CHARS = 256;

const DEFAULT_BODY = "{\"status\":\"ok\",\"queue\":42}";

/**
 * A response reading with sane defaults, overridable per case.
 *
 * Three headers rather than one: a text header, a header whose value is a
 * number (the numeric-operator-on-a-text-target path), and a header the numeric
 * operators cannot read at all.
 */
function reading(overrides: Partial<AssertionSubject> = {}): AssertionSubject {
    return {
        statusCode: 200,
        responseTimeMs: 120,
        headers: {
            "content-type": "application/json",
            "x-cache": "HIT",
            "x-response-count": "42",
        },
        body: {
            text: DEFAULT_BODY,
            truncated: false,
        },
        ...overrides,
    };
}

/** A body override, since every body case needs the same two-field object. */
function body(text: string, truncated = false): Partial<AssertionSubject> {
    return {
        body: {
            text,
            truncated,
        },
    };
}

/**
 * Evaluate one rule and return its outcome.
 *
 * The null guard is deliberate rather than a `!`: a single-rule call returning
 * no report is a broken contract, and it should say so once here instead of
 * surfacing as a confusing property access in whichever case hit it.
 */
function outcomeOf(rule: AssertionRule, subject: Partial<AssertionSubject> = {}): AssertionOutcome {
    const report = evaluateAssertions([rule], reading(subject));
    if (report === null) {
        throw new Error("a non-empty rule set must produce a report");
    }

    return report.results[0];
}

/**
 * The whole contract of one outcome as a single comparable string.
 *
 * A skip carries its reason because the reason is the assertion: "skipped" alone
 * would pass whether the evaluator refused the rule for the documented cause or
 * for an unrelated one, and the closed reason set exists so both sides can
 * branch on it.
 */
function verdictOf(outcome: AssertionOutcome): string {
    return outcome.verdict === "skipped"
        ? `skipped:${outcome.reason}`
        : outcome.verdict;
}

/**
 * A value the `jsonb` column can physically hold and {@link AssertionRule}
 * forbids.
 *
 * The cast is the point of the function. `assertion_rules` is operator-authored
 * JSON, cast wholesale from `request.json()` with nothing validated, and rows
 * written before the save-time validator existed can hold anything at all, so
 * the evaluator has to be handed shapes the type says cannot occur.
 */
function fromTheWire(value: unknown): AssertionRule {
    return value as AssertionRule;
}

/**
 * The same cast for the rule SET rather than for one rule.
 *
 * Separate because the shapes it has to express are the ones the parameter type
 * rules out entirely: `assertion_rules` is a `jsonb` column handed over as
 * whatever `request.json()` produced, so the top level can be an object or a
 * scalar and not merely a list of bad elements.
 */
function setFromTheWire(value: unknown): AssertionRule[] {
    return value as AssertionRule[];
}

describe("evaluateAssertions: no rules", () => {
    it("returns no report at all when the monitor configured no rules", () => {
        // D4, and the null is the whole of it. `monitor_checks.assertions_passed`
        // shipped as `NOT NULL DEFAULT TRUE`, which is why the migration in this
        // change set made it nullable and dropped the default: an empty report or
        // a `passed: true` here would record "every assertion passed" for a
        // monitor that asserts nothing, and a status page would then cite an
        // assertion result that was never measured. NULL is now the only state
        // that says nothing was measured, and only a null report writes it.
        expect(evaluateAssertions(null, reading())).toBeNull();
    });

    it("treats an empty rule list as no rules, not as a vacuous pass", () => {
        // An empty `jsonb` array is what the panel writes when an operator
        // deletes their last rule; it is the same state as never having had one.
        expect(evaluateAssertions([], reading())).toBeNull();
    });

    it("treats an absent field as no rules", () => {
        // A spec from an origin older than this field omits the key entirely, so
        // the evaluator sees `undefined` rather than `null`.
        expect(evaluateAssertions(undefined, reading())).toBeNull();
    });

    it("returns no report for a rule set the column holds as an object, not an array", () => {
        // The `Array.isArray` guard, and the object with numeric keys is not a
        // hypothetical: `AssertionRuleSet` documents it as the ONE shape its
        // save-time screen deliberately accepts, because `json_decode(..., true)`
        // turns `{"0": {...}}` into a PHP list and cannot tell it from one. So the
        // panel saves it, the column stores an object, and this is where it lands.
        //
        // Null and not an empty report: nothing was measured, so D4 applies exactly
        // as it does to a monitor with no rules. And not a throw either, which is
        // the failure the guard is actually there for: `Object.entries`-free
        // `.map()` over an object raises, `probeHttp` has no catch around the
        // evaluator, and a healthy target would be published unreachable on every
        // check.
        expect(evaluateAssertions(setFromTheWire({
            0: {
                target: "status_code",
                operator: "equals",
                value: 200,
            },
        }), reading())).toBeNull();

        expect(evaluateAssertions(setFromTheWire({
            target: "body",
            operator: "exists",
        }), reading())).toBeNull();
    });

    it("returns no report for a scalar in place of a rule set", () => {
        // Costs nothing beside the case above and closes the other half of what a
        // `jsonb` column can physically hold.
        for (const scalar of [42, "body contains ok", true]) {
            expect(evaluateAssertions(setFromTheWire(scalar), reading()), String(scalar)).toBeNull();
        }
    });
});

describe("evaluateAssertions: status_code", () => {
    it("passes an equality against the received code and records it as observed", () => {
        const outcome = outcomeOf({
            target: "status_code",
            operator: "equals",
            value: 200,
        });

        expect(verdictOf(outcome)).toBe("passed");
        expect(outcome.observed).toBe(200);
    });

    it("fails an equality the response does not satisfy", () => {
        const outcome = outcomeOf({
            target: "status_code",
            operator: "equals",
            value: 500,
        });

        expect(verdictOf(outcome)).toBe("failed");
        expect(outcome.observed).toBe(200);
    });

    it("passes not_equals when the code differs", () => {
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "not_equals",
            value: 500,
        }))).toBe("passed");
    });

    it("compares greater_than and less_than strictly", () => {
        // Both bounds are exclusive, so the received code itself is outside its
        // own range. An off-by-one here is a monitor that never fires or always
        // fires, and neither looks like a bug from the outside.
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "greater_than",
            value: 199,
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "greater_than",
            value: 200,
        }))).toBe("failed");
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "less_than",
            value: 300,
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "less_than",
            value: 200,
        }))).toBe("failed");
    });

    it("matches a regex against the code, which is how an operator writes an OR", () => {
        // The operator union documents this: no surveyed product offers OR, and
        // Datadog's users fake alternation with exactly this pattern. It only
        // works if a text operator may address a numeric target, so this case is
        // what makes that legal rather than accidental.
        expect(verdictOf(outcomeOf(
            {
                target: "status_code",
                operator: "matches_regex",
                value: "^(200|302)$",
            },
            {
                statusCode: 302,
            },
        ))).toBe("passed");
    });

    it("passes not_matches_regex when the code is outside the pattern", () => {
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "not_matches_regex",
            value: "^5\\d\\d$",
        }))).toBe("passed");
    });

    it("reads contains against the code's decimal form", () => {
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "contains",
            value: "20",
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "not_contains",
            value: "50",
        }))).toBe("passed");
    });

    it("compares a string-valued equality as text rather than skipping it", () => {
        // The panel's JSON editor makes `"200"` as easy to type as `200`, and
        // both express the same intent. The value's kind picks the comparison
        // mode, so this is a text compare against `String(200)` and not a
        // `value_invalid` skip that would silently stop asserting.
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "equals",
            value: "200",
        }))).toBe("passed");
    });

    it("answers exists from the reading itself", () => {
        // An HTTP reading always carries a code, so this is trivially true. It is
        // still answered rather than skipped: presence is what the operator asked
        // about and the reading can answer it, and inventing a skip for a
        // question the measurement settles would be recording a fault we do not
        // have.
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "exists",
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "not_exists",
        }))).toBe("failed");
    });
});

describe("evaluateAssertions: response_time_ms", () => {
    it("passes a latency ceiling the response is inside", () => {
        const outcome = outcomeOf({
            target: "response_time_ms",
            operator: "less_than",
            value: 500,
        });

        expect(verdictOf(outcome)).toBe("passed");
        expect(outcome.observed).toBe(120);
    });

    it("fails a latency floor the response is under", () => {
        expect(verdictOf(outcomeOf({
            target: "response_time_ms",
            operator: "greater_than",
            value: 500,
        }))).toBe("failed");
    });

    it("treats the boundary as outside an exclusive bound", () => {
        // 500 is not less than 500. The measurement is the reading the probe
        // handed over, never a clock read inside the evaluator, so this case
        // cannot flake.
        expect(verdictOf(outcomeOf(
            {
                target: "response_time_ms",
                operator: "less_than",
                value: 500,
            },
            {
                responseTimeMs: 500,
            },
        ))).toBe("failed");
    });

    it("compares an equality on latency", () => {
        expect(verdictOf(outcomeOf({
            target: "response_time_ms",
            operator: "equals",
            value: 120,
        }))).toBe("passed");
    });

    it("reads a regex against the latency's decimal form", () => {
        expect(verdictOf(outcomeOf({
            target: "response_time_ms",
            operator: "matches_regex",
            value: "^\\d{3}$",
        }))).toBe("passed");
    });
});

describe("evaluateAssertions: body", () => {
    it("compares an exact body equality", () => {
        expect(verdictOf(outcomeOf({
            target: "body",
            operator: "equals",
            value: DEFAULT_BODY,
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "body",
            operator: "not_equals",
            value: DEFAULT_BODY,
        }))).toBe("failed");
    });

    it("answers contains in both directions", () => {
        expect(verdictOf(outcomeOf({
            target: "body",
            operator: "contains",
            value: "\"status\":\"ok\"",
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "body",
            operator: "contains",
            value: "\"status\":\"degraded\"",
        }))).toBe("failed");
        expect(verdictOf(outcomeOf({
            target: "body",
            operator: "not_contains",
            value: "Fatal error",
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "body",
            operator: "not_contains",
            value: "\"status\"",
        }))).toBe("failed");
    });

    it("answers matches_regex in both directions", () => {
        expect(verdictOf(outcomeOf({
            target: "body",
            operator: "matches_regex",
            value: "\"queue\":\\s*\\d+",
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "body",
            operator: "not_matches_regex",
            value: "\"queue\":\\s*\\d+",
        }))).toBe("failed");
    });

    it("measures presence as a non-empty body", () => {
        // A 204 and a page that came back blank are the same reading, and it is a
        // reading: the response was received, so this is a measurement of the
        // target and not a skip.
        expect(verdictOf(outcomeOf({
            target: "body",
            operator: "exists",
        }))).toBe("passed");
        expect(verdictOf(outcomeOf(
            {
                target: "body",
                operator: "exists",
            },
            body(""),
        ))).toBe("failed");
        expect(verdictOf(outcomeOf(
            {
                target: "body",
                operator: "not_exists",
            },
            body(""),
        ))).toBe("passed");
    });

    it("compares a numeric operator against a numeric body", () => {
        // A bare-number endpoint (a queue depth, a replica lag) is the whole
        // reason a numeric operator may address a text target.
        expect(verdictOf(outcomeOf(
            {
                target: "body",
                operator: "greater_than",
                value: 40,
            },
            body("42"),
        ))).toBe("passed");
        expect(verdictOf(outcomeOf(
            {
                target: "body",
                operator: "less_than",
                value: 40,
            },
            body("42"),
        ))).toBe("failed");
        expect(verdictOf(outcomeOf(
            {
                target: "body",
                operator: "greater_than",
                value: 40,
            },
            body("  42\n"),
        ))).toBe("passed");
    });

    it("skips a numeric operator over a body that is not a number", () => {
        // The decision this case pins, and it is a decision: an ordering over
        // text that is not a number is UNANSWERABLE, not false. The alternative
        // reads better for one scenario (an endpoint that served 42 and now
        // serves "error") and is a permanent false outage in the far more common
        // one (a numeric rule pointed at an HTML page), which is a fault in our
        // configuration and D2 says a fault of ours never becomes a verdict. The
        // skip records the mismatch where an operator can see it.
        const outcome = outcomeOf(
            {
                target: "body",
                operator: "greater_than",
                value: 40,
            },
            body("not a number"),
        );

        expect(verdictOf(outcome)).toBe("skipped:value_invalid");
    });

    it("compares a served float numerically, not by its printed form", () => {
        // Trap 1, first half. `String(0.0000003)` is "3e-7", so a naive
        // `text === String(value)` reports a served 0.0000003 as unequal to the
        // operator's own 0.0000003. UptimeRobot's documented example is exactly
        // this shape, a JSON price of 0.000003.
        expect(verdictOf(outcomeOf(
            {
                target: "body",
                operator: "equals",
                value: 0.0000003,
            },
            body("0.0000003"),
        ))).toBe("passed");

        // The same failure with no exponent in sight: "1.0" and "1" are the same
        // number and different strings.
        expect(verdictOf(outcomeOf(
            {
                target: "body",
                operator: "equals",
                value: 1,
            },
            body("1.0"),
        ))).toBe("passed");
    });

    it("tolerates the float expansion an endpoint actually serves", () => {
        // Trap 1, second half, and the case that forces a tolerance rather than
        // just a parse of both sides: an endpoint serving a computed float prints
        // the double's full expansion, and a bit-exact compare against the
        // operator's 0.3 is a false failure on every check.
        expect(verdictOf(outcomeOf(
            {
                target: "body",
                operator: "equals",
                value: 0.3,
            },
            body("0.30000000000000004"),
        ))).toBe("passed");
    });

    it("does not tolerate a genuinely different number", () => {
        // The other side of the tolerance, so "compare with epsilon" cannot be
        // satisfied by an epsilon wide enough to make every number equal. 1e-7
        // apart is a real difference; 5e-17 apart is the same double printed
        // differently.
        expect(verdictOf(outcomeOf(
            {
                target: "body",
                operator: "equals",
                value: 42,
            },
            body("42.0000001"),
        ))).toBe("failed");
    });

    it("records the observed body as a bounded excerpt", () => {
        // `assertion_results` rides the Redis `processing` queue, where queue
        // keys carry no TTL and the `volatile-lru` eviction victims are the
        // persistence locks. `content` is excluded from that payload for exactly
        // this reason, so recording a whole page as the observed value would
        // reintroduce it through the back door.
        const text = "a".repeat(1024);

        // The case is only honest if 1024 characters are comfortably inside the
        // evaluation ceiling; otherwise it would be measuring `body_too_large`.
        expect(ASSERTION_BODY_MAX_CHARS).toBeGreaterThan(text.length);

        const outcome = outcomeOf(
            {
                target: "body",
                operator: "contains",
                value: "aaa",
            },
            body(text),
        );

        expect(verdictOf(outcome)).toBe("passed");
        expect(outcome.observed).toBe(text.slice(0, OBSERVED_EXCERPT_CHARS));
    });

    it("skips a rule whose body exceeded the evaluation ceiling", () => {
        // D5's edge half. The pattern is chosen so a prefix evaluation would
        // report `passed`: an implementation that ran the regex against whatever
        // it was handed would look correct here, and it would both be running a
        // stored pattern against an unbounded string and certifying a match it
        // only saw part of the evidence for.
        const outcome = outcomeOf(
            {
                target: "body",
                operator: "matches_regex",
                value: "^x+",
            },
            body("x".repeat(ASSERTION_BODY_MAX_CHARS + 1)),
        );

        expect(verdictOf(outcome)).toBe("skipped:body_too_large");
    });

    it("skips a negated rule over an oversized body too", () => {
        // The other direction, and the one a "skip only what cannot pass"
        // shortcut gets wrong: "does not contain" over a prefix is vacuously
        // true, which certifies what was never measured. This repository has
        // already paid for that once.
        expect(verdictOf(outcomeOf(
            {
                target: "body",
                operator: "not_contains",
                value: "Fatal error",
            },
            body("x".repeat(ASSERTION_BODY_MAX_CHARS + 1)),
        ))).toBe("skipped:body_too_large");
    });

    it("skips a rule over a short body the probe truncated", () => {
        // Length alone cannot prove completeness: an origin whose
        // `content-archive.max_bytes` is a few KiB hands the evaluator a fragment
        // of a megabyte page, well inside the ceiling. `truncated` is the only
        // signal that says so, and without honouring it a match found in the
        // fragment would pass and a match past the cut would fail, both wrongly.
        expect(verdictOf(outcomeOf(
            {
                target: "body",
                operator: "contains",
                value: "\"status\":\"ok\"",
            },
            body(DEFAULT_BODY, true),
        ))).toBe("skipped:body_too_large");
    });
});

describe("evaluateAssertions: header", () => {
    it("compares a header the response carried", () => {
        const outcome = outcomeOf({
            target: "header",
            operator: "equals",
            name: "content-type",
            value: "application/json",
        });

        expect(verdictOf(outcome)).toBe("passed");
        expect(outcome.observed).toBe("application/json");
    });

    it("resolves the header name case-insensitively in both directions", () => {
        // HTTP header names are case-insensitive and the subject is a plain
        // record, so the folding has to happen in the evaluator. Both directions
        // are real: `extractHeaders` lowercases what the runtime gives it, while
        // an operator types `Content-Type` in the panel.
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "equals",
            name: "Content-Type",
            value: "application/json",
        }))).toBe("passed");

        expect(verdictOf(outcomeOf(
            {
                target: "header",
                operator: "equals",
                name: "content-type",
                value: "application/json",
            },
            {
                headers: {
                    "Content-Type": "application/json",
                },
            },
        ))).toBe("passed");
    });

    it("answers contains and matches_regex over the header value", () => {
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "contains",
            name: "content-type",
            value: "json",
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "not_contains",
            name: "content-type",
            value: "xml",
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "matches_regex",
            name: "content-type",
            value: "^application/(json|ld\\+json)$",
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "not_matches_regex",
            name: "x-cache",
            value: "^MISS$",
        }))).toBe("passed");
    });

    it("compares a numeric operator against a numeric header value", () => {
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "greater_than",
            name: "x-response-count",
            value: 40,
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "less_than",
            name: "x-response-count",
            value: 40,
        }))).toBe("failed");
    });

    it("skips a numeric operator over a header that is not a number", () => {
        // Same reasoning as the body case: the rule does not fit the header it
        // names, which is our configuration and not the target's health.
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "greater_than",
            name: "x-cache",
            value: 40,
        }))).toBe("skipped:value_invalid");
    });

    it("measures presence and absence without ever skipping", () => {
        // `exists` and `not_exists` measure presence itself, so an absent header
        // is the answer rather than an obstacle to it. These two are the only
        // operators for which that holds.
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "exists",
            name: "x-cache",
        }))).toBe("passed");
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "not_exists",
            name: "x-cache",
        }))).toBe("failed");
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "exists",
            name: "x-request-id",
        }))).toBe("failed");
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "not_exists",
            name: "x-request-id",
        }))).toBe("passed");
    });

    it("fails a positive comparison over a header the response did not carry", () => {
        // The asymmetry, positive half. The asserted content is demonstrably not
        // there, and that is a measurement of the target rather than of us: a
        // header that vanished (a cache layer dropped, a security header removed
        // by a bad deploy) is exactly what the operator wrote the rule to catch.
        const positives: Array<{ operator: AssertionRule["operator"]; value: string | number }> = [
            {
                operator: "equals",
                value: "HIT",
            },
            {
                operator: "contains",
                value: "HIT",
            },
            {
                operator: "greater_than",
                value: 40,
            },
            {
                operator: "less_than",
                value: 40,
            },
            {
                operator: "matches_regex",
                value: "^HIT$",
            },
        ];

        for (const positive of positives) {
            const outcome = outcomeOf({
                target: "header",
                operator: positive.operator,
                name: "x-request-id",
                value: positive.value,
            });

            expect(verdictOf(outcome), positive.operator).toBe("failed");
            expect(outcome.observed, positive.operator).toBeNull();
        }
    });

    it("skips a negated comparison over a header the response did not carry", () => {
        // The asymmetry, negated half, and the single most likely thing to get
        // backwards. "Does not contain error" over a header that was never sent
        // is vacuously true, so passing it would certify what was never
        // measured; the skip says so instead.
        const negated: Array<AssertionRule["operator"]> = [
            "not_equals",
            "not_contains",
            "not_matches_regex",
        ];

        for (const operator of negated) {
            const outcome = outcomeOf({
                target: "header",
                operator,
                name: "x-request-id",
                value: "HIT",
            });

            expect(verdictOf(outcome), operator).toBe("skipped:header_absent");
            expect(outcome.observed, operator).toBeNull();
        }
    });

    it("skips a header rule that names no header", () => {
        // Three shapes of the same fault, because the panel can produce all
        // three: the key omitted, an explicit null from the `jsonb` column, and
        // an empty string left behind by a cleared field.
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "exists",
        }))).toBe("skipped:header_name_missing");
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "equals",
            name: null,
            value: "application/json",
        }))).toBe("skipped:header_name_missing");
        expect(verdictOf(outcomeOf({
            target: "header",
            operator: "equals",
            name: "   ",
            value: "application/json",
        }))).toBe("skipped:header_name_missing");
    });
});

describe("evaluateAssertions: a fault of ours is never a verdict", () => {
    it("skips an operator this build does not implement", () => {
        // D2. An origin that learns an operator before this worker is deployed
        // must not turn the difference into an outage on every monitor that used
        // it, and the rule is echoed so the recorded outcome explains itself
        // without a join against a monitor that may have been edited since.
        const rule = fromTheWire({
            target: "status_code",
            operator: "starts_with",
            value: "20",
        });
        const outcome = outcomeOf(rule);

        expect(verdictOf(outcome)).toBe("skipped:unknown_operator");
        expect(outcome.rule).toEqual(rule);
    });

    it("skips a target this build does not implement", () => {
        // `json_path` is deliberately absent from v1 rather than merely
        // unimplemented, so it is the honest example: whichever dialect is
        // eventually picked, a spec carrying it today is answered with a skip.
        const outcome = outcomeOf(fromTheWire({
            target: "json_path",
            operator: "equals",
            value: "ok",
        }));

        expect(verdictOf(outcome)).toBe("skipped:unknown_target");
    });

    it("skips an array element that is not a rule at all, with no rule to echo", () => {
        // A row written before the save-time validator existed can hold
        // anything. `rule` is null here and only here: there was no rule.
        const junk: unknown[] = [
            "body contains ok",
            null,
            42,
            [],
            {
                operator: "equals",
                value: "ok",
            },
            {
                target: 7,
                operator: "equals",
                value: "ok",
            },
            {
                target: "body",
                operator: null,
                value: "ok",
            },
        ];

        for (const element of junk) {
            const outcome = outcomeOf(fromTheWire(element));

            expect(verdictOf(outcome), JSON.stringify(element)).toBe("skipped:rule_malformed");
            expect(outcome.rule, JSON.stringify(element)).toBeNull();
            expect(outcome.observed, JSON.stringify(element)).toBeNull();
        }
    });

    it("skips a pattern that does not compile in this runtime", () => {
        // A stored pattern that no longer compiles is our build or our
        // configuration, never the target's health. It also must not throw: one
        // bad pattern would take the whole probe down and report a target that
        // is up as unreachable.
        expect(verdictOf(outcomeOf({
            target: "body",
            operator: "matches_regex",
            value: "([unclosed",
        }))).toBe("skipped:regex_invalid");
        expect(verdictOf(outcomeOf({
            target: "body",
            operator: "not_matches_regex",
            value: "([unclosed",
        }))).toBe("skipped:regex_invalid");
    });

    it("skips an operator that needs a value when none arrived", () => {
        const missing: Array<AssertionRule["operator"]> = [
            "equals",
            "not_equals",
            "contains",
            "not_contains",
            "greater_than",
            "less_than",
            "matches_regex",
            "not_matches_regex",
        ];

        for (const operator of missing) {
            expect(verdictOf(outcomeOf({
                target: "body",
                operator,
            })), operator).toBe("skipped:value_invalid");
            expect(verdictOf(outcomeOf({
                target: "body",
                operator,
                value: null,
            })), `${operator} (explicit null)`).toBe("skipped:value_invalid");
        }
    });

    it("skips a numeric operator whose own value is not a number", () => {
        expect(verdictOf(outcomeOf({
            target: "status_code",
            operator: "greater_than",
            value: "forty",
        }))).toBe("skipped:value_invalid");
    });

    it("calls a report whose every outcome is skipped passed", () => {
        // This looks wrong until the alternative is spelled out: `passed: false`
        // here publishes the reading DOWN because OUR rules were unusable, which
        // pages the on-call for a target that is fine. Nothing was measured, so
        // nothing failed.
        //
        // Checked against the stub question and it is the one case the "always
        // return one skipped outcome" stub would satisfy: the shape IS the
        // assertion, so it stands as a regression guard rather than a
        // discriminator.
        const report = evaluateAssertions(
            [
                fromTheWire({
                    target: "body",
                    operator: "starts_with",
                    value: "ok",
                }),
                {
                    target: "body",
                    operator: "matches_regex",
                    value: "([unclosed",
                },
            ],
            reading(),
        );

        expect(report).not.toBeNull();
        expect(report?.results.map(verdictOf)).toEqual([
            "skipped:unknown_operator",
            "skipped:regex_invalid",
        ]);
        expect(report?.passed).toBe(true);
    });
});

describe("evaluateAssertions: every rule must pass", () => {
    it("fails the report when a later rule fails", () => {
        const report = evaluateAssertions(
            [
                {
                    target: "status_code",
                    operator: "equals",
                    value: 200,
                },
                {
                    target: "body",
                    operator: "contains",
                    value: "Fatal error",
                },
            ],
            reading(),
        );

        expect(report?.passed).toBe(false);
        expect(report?.results.map(verdictOf)).toEqual([
            "passed",
            "failed",
        ]);
    });

    it("records every outcome when the FIRST rule already failed", () => {
        // The reversed order, because a short-circuit that stops at the first
        // failure would produce the right verdict and a one-element `results`,
        // and the position of a rule in `results` is its only identity: nothing
        // else is stored beside it. A dropped outcome makes the stored record
        // describe a different rule than the one it came from.
        const report = evaluateAssertions(
            [
                {
                    target: "body",
                    operator: "contains",
                    value: "Fatal error",
                },
                {
                    target: "status_code",
                    operator: "equals",
                    value: 200,
                },
            ],
            reading(),
        );

        expect(report?.passed).toBe(false);
        expect(report?.results.map(verdictOf)).toEqual([
            "failed",
            "passed",
        ]);
        expect(report?.results.map((outcome: AssertionOutcome) => outcome.rule?.target)).toEqual([
            "body",
            "status_code",
        ]);
    });

    it("passes the report only when no rule failed", () => {
        const report = evaluateAssertions(
            [
                {
                    target: "status_code",
                    operator: "equals",
                    value: 200,
                },
                {
                    target: "body",
                    operator: "contains",
                    value: "\"status\":\"ok\"",
                },
                {
                    target: "header",
                    operator: "exists",
                    name: "content-type",
                },
            ],
            reading(),
        );

        // Asserting the three verdicts as well as the report's own, so the case
        // is not satisfiable by a stub that skips everything and calls it passed.
        expect(report?.results.map(verdictOf)).toEqual([
            "passed",
            "passed",
            "passed",
        ]);
        expect(report?.passed).toBe(true);
    });

    it("does not let a skipped rule fail the report", () => {
        const report = evaluateAssertions(
            [
                {
                    target: "status_code",
                    operator: "equals",
                    value: 200,
                },
                fromTheWire({
                    target: "body",
                    operator: "starts_with",
                    value: "ok",
                }),
            ],
            reading(),
        );

        expect(report?.results.map(verdictOf)).toEqual([
            "passed",
            "skipped:unknown_operator",
        ]);
        expect(report?.passed).toBe(true);
    });

    it("keeps failing the report when a failure sits beside a skip", () => {
        // A skip must not launder a failure: the one rule that WAS measured said
        // the target is broken.
        const report = evaluateAssertions(
            [
                fromTheWire({
                    target: "body",
                    operator: "starts_with",
                    value: "ok",
                }),
                {
                    target: "status_code",
                    operator: "equals",
                    value: 500,
                },
            ],
            reading(),
        );

        expect(report?.results.map(verdictOf)).toEqual([
            "skipped:unknown_operator",
            "failed",
        ]);
        expect(report?.passed).toBe(false);
    });
});
