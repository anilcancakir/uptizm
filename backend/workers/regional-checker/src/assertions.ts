/**
 * Response-assertion evaluation for one probe reading.
 *
 * Until this module existed the edge accepted `assertion_rules` on every signed
 * spec and read none of them, so a monitor whose body assertion was violated was
 * published `up`: the exact silence the operator wrote the rule to break.
 *
 * Two properties hold over everything below and both are load-bearing:
 *
 * 1. A fault of OURS is never a verdict about the target. Every member of
 *    `AssertionSkipReason` names a fault in our configuration or our build, so
 *    each one is RECORDED and none of them fails the check, the same reasoning
 *    `probe_refused` and the `default` branch of `authHeaders` already carry. The
 *    corollary is the part that reads wrong at first: a report whose every
 *    outcome is skipped carries `passed: true`, because nothing was measured, so
 *    nothing failed. The alternative pages the on-call for our own bad config.
 * 2. Nothing here throws. One stored pattern that no longer compiles in this
 *    runtime would otherwise abort the probe and publish a target that is up as
 *    unreachable.
 *
 * This is a module of its own rather than a function inside `regional-probe.ts`
 * because that module imports this one. The types travel the other way through
 * `import type`, which erases at compile time; a VALUE imported from there would
 * close a runtime cycle, which is why {@link ASSERTION_BODY_MAX_CHARS} lives
 * here.
 */

import type {
    AssertionObserved,
    AssertionOperator,
    AssertionOutcome,
    AssertionReport,
    AssertionRule,
    AssertionSkipReason,
    AssertionTarget,
} from "./regional-probe";

/**
 * The largest body, in CHARACTERS, a rule is ever compared against.
 *
 * Equal to the origin's own default `content-archive.max_bytes` (mirrored in
 * `regional-probe.ts` as `CONTENT_MAX_BYTES_FALLBACK`), and that equality is the
 * point rather than a coincidence. UTF-8 decodes to at most one character per
 * byte, so a body the probe read WHOLE under the default byte ceiling can never
 * exceed this character ceiling: at the shipped configuration this guard is
 * unreachable, and no ordinary page silently stops being asserted. That failure
 * direction matters as much as the other one, because a rule that quietly stops
 * running is a false `up`. The guard becomes reachable only when an operator
 * raises `max_bytes` past the default, which is exactly when a backstop is
 * wanted.
 *
 * It is NOT the ReDoS defence and must not be read as one. A catastrophically
 * backtracking pattern is unusable at any body size worth evaluating (a
 * quadratic scan over even 64 KiB is already ~1e9 steps), so the containment is
 * the save-time screen per D5: a 200-character cap, nested-quantifier detection
 * and a trial compile. What this bounds is the linear case and the unbounded
 * one: `String.includes` and a well-formed pattern over 1 MiB are milliseconds,
 * and a stored pattern never meets a string with no ceiling at all.
 */
export const ASSERTION_BODY_MAX_CHARS = 1_048_576;

/**
 * How much of a text target is recorded as the observed value.
 *
 * `assertion_results` rides to the persisting job through the Redis `processing`
 * queue, from which `content` is deliberately excluded so a 1 MB page never
 * enters a store whose queue keys carry no TTL and whose `volatile-lru` eviction
 * victims are the persistence locks. Recording a whole body as the observed
 * value would reintroduce that through the back door.
 */
const OBSERVED_EXCERPT_CHARS = 256;

/**
 * How far apart two numbers may be and still count as equal, relative to the
 * larger of the two.
 *
 * Numeric equality is never `===`. UptimeRobot's own worked example
 * equality-compares a JSON price of `0.000003`, and an endpoint serving a
 * computed float prints the double's full decimal expansion, so a bit-exact
 * compare against an operator's `0.3` is a false failure on every check. A few
 * ulps is the whole band that is wanted: it admits `0.30000000000000004` against
 * `0.3` (a quarter of one ulp apart) while `42.0000001` against `42` is six
 * orders of magnitude outside it, so the tolerance is bounded from both sides
 * and cannot be widened into "every number is equal" without failing one of
 * them.
 */
const NUMERIC_EQUALITY_TOLERANCE = Number.EPSILON * 8;

/**
 * The reading a rule is evaluated against.
 *
 * A plain reading rather than the `Response`, so the evaluator is pure and
 * `probeHttp` assembles it from values it already holds: `response.status`, the
 * elapsed ms it already computes, `extractHeaders(response.headers)`, and the
 * single body read. Headers arrive as a `Record` because that is what
 * `extractHeaders` and `CheckResultPayload.response_headers` already produce, so
 * the case-folding HTTP header names require lives in this module.
 *
 * The body carries `truncated` beside its text because length alone cannot prove
 * completeness: an origin whose `max_bytes` is a few KiB hands over a fragment of
 * a megabyte page, well inside {@link ASSERTION_BODY_MAX_CHARS}. A comparison
 * over a prefix is wrong in both directions (a match past the cut reads as a
 * non-match), so a prefix is not compared at all.
 */
export type AssertionSubject = {
    statusCode: number;
    responseTimeMs: number;
    headers: Record<string, string>;
    body: {
        text: string;
        truncated: boolean;
    };
};

/**
 * The vocabulary this build implements, as runtime sets.
 *
 * Sets of plain strings and not of the union members, because the whole point is
 * to answer a value the type promises and the wire does not guarantee: the spec
 * is cast from `request.json()`, and a rule stored before the save-time
 * validator existed can name anything at all.
 */
/**
 * Both sets are DERIVED from the unions rather than retyped as bare literals.
 *
 * A `Record` keyed by the union is exhaustive: omit a member and this file does
 * not compile. Retyping the strings made these a THIRD uncoupled copy of the
 * vocabulary beside the unions in `regional-probe.ts` and
 * `AssertionRuleSet::TARGETS`/`OPERATORS` in PHP, so adding an operator to the
 * union and to the validator but not here compiled clean, saved in the panel and
 * skipped forever at the edge as `unknown_operator`. That is precisely the silent
 * no-op this module was written to end.
 *
 * The SETS stay `ReadonlySet<string>` on purpose, because what they answer is an
 * unvalidated wire value: the spec is cast from `request.json()` and a rule stored
 * before the save-time validator existed can name anything at all. Typing the
 * parameter would defeat the guard; typing the contents catches the typo. Only the
 * PHP side remains a hand-kept mirror, and the two docblocks name each other.
 */
const TARGET_MEMBERS: Record<AssertionTarget, true> = {
    status_code: true,
    response_time_ms: true,
    body: true,
    header: true,
};

const OPERATOR_MEMBERS: Record<AssertionOperator, true> = {
    equals: true,
    not_equals: true,
    contains: true,
    not_contains: true,
    greater_than: true,
    less_than: true,
    matches_regex: true,
    not_matches_regex: true,
    exists: true,
    not_exists: true,
};

const TARGETS: ReadonlySet<string> = new Set(Object.keys(TARGET_MEMBERS));

const OPERATORS: ReadonlySet<string> = new Set(Object.keys(OPERATOR_MEMBERS));

/**
 * The comparisons that are vacuously true over a value that was never sent.
 *
 * `not_exists` is deliberately absent: presence is what it measures, so an
 * absent header is its answer rather than an obstacle to it.
 */
const NEGATED_OPERATORS: ReadonlySet<string> = new Set([
    "not_equals",
    "not_contains",
    "not_matches_regex",
]);

/**
 * Evaluate a monitor's rules against the reading the probe took.
 *
 * The comparison contract, stated once here rather than inferred from the
 * branches below:
 *
 *   - `greater_than` / `less_than` compare numerically, always.
 *   - `contains` / `not_contains` / `matches_regex` / `not_matches_regex` compare
 *     as text, always; a numeric target contributes its decimal string, which is
 *     what makes an operator's `200|302` regex work as the alternation the
 *     operator set deliberately does not offer.
 *   - `equals` / `not_equals` follow the KIND OF THE RULE'S VALUE: a JSON number
 *     compares numerically, a string compares as text. Both spellings reach the
 *     panel's JSON editor equally easily and both express the same intent.
 *   - `exists` / `not_exists` measure presence and read no value.
 *   - Numeric mode reads a text target through `Number(...)`; text that is not a
 *     number is a `value_invalid` skip and NOT a failure, because an ordering
 *     over a non-number is unanswerable rather than false, and a rule aimed at
 *     the wrong endpoint would otherwise page someone on every single check.
 *
 * @param  rules  The monitor's rules, exactly as the signed spec carried them.
 * @param  subject  What the probe measured.
 * @return The report, or null when the monitor configured no rules at all. Null
 *         and not an empty report (D4): `monitor_checks.assertions_passed` would
 *         otherwise record "every assertion passed" for a monitor that asserts
 *         nothing, and a status page would cite a result never measured.
 */
export function evaluateAssertions(
    rules: AssertionRule[] | null | undefined,
    subject: AssertionSubject,
): AssertionReport | null {
    // `Array.isArray` rather than a null check: `assertion_rules` is a `jsonb`
    // column cast straight off the wire, so it can be an object or a scalar, and
    // mapping over one would throw and turn a healthy target into an
    // unreachable one.
    if (!Array.isArray(rules) || rules.length === 0) {
        return null;
    }

    // Every rule is evaluated, including those after a failure. A rule's
    // position in `results` is its only identity, so a short circuit would make
    // a stored outcome describe a different rule than the one it came from.
    const results = rules.map((rule) => evaluateRule(rule, subject));

    return {
        passed: !results.some((outcome) => outcome.verdict === "failed"),
        results,
    };
}

/**
 * The rule's own value, compiled into the single predicate that answers it.
 *
 * A predicate rather than a mode plus a bare value, because the mode and the
 * comparison it implies are ONE decision (`greater_than` is numeric, `contains`
 * is text, `equals` follows the kind of the value the operator wrote) and
 * splitting them across two switches is how the two drift apart.
 */
type Comparison =
    | {
        mode: "numeric";
        test: (actual: number) => boolean;
    }
    | {
        mode: "text";
        test: (actual: string) => boolean;
    };

/** What the reading offers for a rule's target. */
type TargetRead =
    | {
        state: "read";

        /** The target as text, for a text comparison. */
        text: string;

        /** The target as a number, or null when it is not one. */
        numeric: number | null;

        observed: AssertionObserved;
    }
    | {
        /** A `header` rule naming a header the response did not carry. */
        state: "absent";
    }
    | {
        /** The target was there and must still not be compared against. */
        state: "unusable";
        reason: AssertionSkipReason;
    };

/**
 * Decide one rule.
 *
 * The order of the phases is the contract, not an implementation detail. Faults
 * in the RULE are settled first and without reference to the response, so a
 * broken rule can never be reported as a broken target; only then is the target
 * read, and only then compared.
 */
function evaluateRule(candidate: AssertionRule, subject: AssertionSubject): AssertionOutcome {
    // 1. Is this a rule at all? A row written before the save-time validator
    //    existed can hold any JSON, so nothing the type promises is known yet.
    //    There is no rule to echo, and this is the only case where that is true.
    const rule = asRule(candidate);
    if (rule === null) {
        return skipped("rule_malformed", null, null);
    }

    // 2. Vocabulary this build implements. An origin that has learned a target
    //    or an operator before this worker is deployed is not wrong, it is ahead,
    //    and the difference must not become an outage on every monitor using it.
    if (!TARGETS.has(rule.target)) {
        return skipped("unknown_target", rule, null);
    }
    if (!OPERATORS.has(rule.operator)) {
        return skipped("unknown_operator", rule, null);
    }

    // 3. A `header` rule that names no header is aimed at nothing. All three
    //    shapes the panel can produce (the key omitted, an explicit null, a
    //    cleared field left blank) are the same fault.
    const header = rule.target === "header" ? headerName(rule) : null;
    if (rule.target === "header" && header === null) {
        return skipped("header_name_missing", rule, null);
    }

    // 4. `exists` / `not_exists` measure presence itself, so the reading always
    //    answers them: they are the only operators that never skip past here.
    if (rule.operator === "exists" || rule.operator === "not_exists") {
        const presence = resolvePresence(rule, header, subject);

        return measured(
            (rule.operator === "exists") === presence.present,
            rule,
            presence.observed,
        );
    }

    // 5. The rule against ITSELF: a missing value, a bound that is not a number,
    //    a pattern this runtime rejects. Each is our stored configuration, so it
    //    is answered before the response is consulted and can never surface as
    //    the target failing.
    const comparison = comparisonFor(rule);
    if (typeof comparison === "string") {
        return skipped(comparison, rule, null);
    }

    // 6. Read the target.
    const read = readTarget(rule, header, subject);
    if (read.state === "unusable") {
        return skipped(read.reason, rule, null);
    }
    if (read.state === "absent") {
        // The asymmetry, and the single most likely thing to get backwards. A
        // header that vanished is what the positive rule was written to catch,
        // so it FAILS; "does not contain error" over a header never sent is
        // vacuously true, so it skips rather than certifying what was never
        // measured.
        return NEGATED_OPERATORS.has(rule.operator)
            ? skipped("header_absent", rule, null)
            : measured(false, rule, null);
    }

    // 7. Compare, in the mode the rule's own value fixed. A numeric comparison
    //    over a target that is not a number records the mismatch where an
    //    operator can see it instead of answering a question that has no answer.
    if (comparison.mode === "numeric") {
        if (read.numeric === null) {
            return skipped("value_invalid", rule, read.observed);
        }

        return measured(comparison.test(read.numeric), rule, read.observed);
    }

    return measured(comparison.test(read.text), rule, read.observed);
}

/**
 * Narrow one array element to a rule, or refuse it.
 *
 * The parameter is typed as an `AssertionRule` and treated as `unknown`, which is
 * the honest reading of both: the wire promises this shape and validates none of
 * it.
 */
function asRule(candidate: AssertionRule): AssertionRule | null {
    const element: unknown = candidate;
    if (typeof element !== "object" || element === null || Array.isArray(element)) {
        return null;
    }

    const fields = element as Record<string, unknown>;
    if (typeof fields.target !== "string" || typeof fields.operator !== "string") {
        return null;
    }

    return candidate;
}

/** The header a rule names, or null when it names none usably. */
function headerName(rule: AssertionRule): string | null {
    const name: unknown = rule.name;
    if (typeof name !== "string" || name.trim() === "") {
        return null;
    }

    return name;
}

/**
 * Compile a rule's operator and value into the predicate that answers it, or
 * name the reason the rule cannot answer anything.
 */
function comparisonFor(rule: AssertionRule): Comparison | AssertionSkipReason {
    const value = rule.value;

    // Eight of the ten operators need a value; the two that do not were answered
    // from presence before this was reached. Absent, an explicit null from the
    // column, and a shape the type forbids but the wire can carry are one fault.
    if (typeof value !== "string" && typeof value !== "number") {
        return "value_invalid";
    }

    switch (rule.operator) {
        case "greater_than":
        case "less_than": {
            const expected = toNumber(value);
            if (expected === null) {
                return "value_invalid";
            }

            return {
                mode: "numeric",
                test: rule.operator === "greater_than"
                    ? (actual) => actual > expected
                    : (actual) => actual < expected,
            };
        }

        case "equals":
        case "not_equals": {
            const negated = rule.operator === "not_equals";

            if (typeof value === "number") {
                const expected = value;

                return {
                    mode: "numeric",
                    test: negated
                        ? (actual) => !numbersEqual(actual, expected)
                        : (actual) => numbersEqual(actual, expected),
                };
            }

            const expected = value;

            return {
                mode: "text",
                test: negated
                    ? (actual) => actual !== expected
                    : (actual) => actual === expected,
            };
        }

        case "contains":
        case "not_contains": {
            const expected = String(value);

            return {
                mode: "text",
                test: rule.operator === "contains"
                    ? (actual) => actual.includes(expected)
                    : (actual) => !actual.includes(expected),
            };
        }

        case "matches_regex":
        case "not_matches_regex": {
            const pattern = compilePattern(String(value));
            if (pattern === null) {
                return "regex_invalid";
            }

            return {
                mode: "text",
                test: rule.operator === "matches_regex"
                    ? (actual) => pattern.test(actual)
                    : (actual) => !pattern.test(actual),
            };
        }

        case "exists":
        case "not_exists":
            // Unreachable: both were answered from presence. Answered with a
            // skip rather than a throw or a verdict, so even an impossible path
            // keeps property 1 of this module.
            return "value_invalid";
    }
}

/**
 * Read the target a rule addresses.
 *
 * The body guard runs here, before any pattern meets any string: it is the whole
 * of D5's edge half, and it applies to a negated rule as much as to a positive
 * one, since "does not contain" over a prefix is the vacuous truth that certifies
 * what was never measured.
 */
function readTarget(
    rule: AssertionRule,
    header: string | null,
    subject: AssertionSubject,
): TargetRead {
    switch (rule.target) {
        case "status_code":
            return fromNumber(subject.statusCode);

        case "response_time_ms":
            return fromNumber(subject.responseTimeMs);

        case "body": {
            const { text, truncated } = subject.body;
            if (truncated || text.length > ASSERTION_BODY_MAX_CHARS) {
                return {
                    state: "unusable",
                    reason: "body_too_large",
                };
            }

            return fromText(text);
        }

        case "header": {
            // `header` is non-null for a `header` rule by the time this runs; the
            // guard is what makes that visible rather than assumed.
            const value = header === null ? undefined : findHeader(subject.headers, header);

            return value === undefined
                ? {
                    state: "absent",
                }
                : fromText(value);
        }
    }
}

/**
 * Whether the target is there at all, which is all `exists` / `not_exists` ask.
 *
 * An HTTP reading always carries a code and a latency, so both are trivially
 * present: the question the operator asked is answered rather than skipped,
 * because inventing a skip for something the measurement settles would record a
 * fault we do not have. A body is present when it is non-empty, which makes a 204
 * and a page that came back blank the same reading, and it IS a reading.
 */
function resolvePresence(
    rule: AssertionRule,
    header: string | null,
    subject: AssertionSubject,
): { present: boolean; observed: AssertionObserved } {
    switch (rule.target) {
        case "status_code":
            return {
                present: true,
                observed: subject.statusCode,
            };

        case "response_time_ms":
            return {
                present: true,
                observed: subject.responseTimeMs,
            };

        case "body":
            return {
                present: subject.body.text !== "",
                observed: excerpt(subject.body.text),
            };

        case "header": {
            const value = header === null ? undefined : findHeader(subject.headers, header);

            return {
                present: value !== undefined,
                observed: value === undefined ? null : excerpt(value),
            };
        }
    }
}

/** A numeric target, which every comparison mode can read. */
function fromNumber(value: number): TargetRead {
    return {
        state: "read",
        text: String(value),
        numeric: value,
        observed: value,
    };
}

/** A text target, numeric only when its text happens to be a number. */
function fromText(value: string): TargetRead {
    return {
        state: "read",
        text: value,
        numeric: toNumber(value),
        observed: excerpt(value),
    };
}

/**
 * Resolve a header case-insensitively.
 *
 * The folding lives here because the subject is a plain record and neither side
 * can be assumed folded already: `extractHeaders` lowercases whatever the
 * runtime hands it, while an operator types `Content-Type` into the panel.
 */
function findHeader(headers: Record<string, string>, name: string): string | undefined {
    const wanted = name.trim().toLowerCase();

    for (const [key, value] of Object.entries(headers)) {
        if (key.trim().toLowerCase() === wanted) {
            return value;
        }
    }

    return undefined;
}

/**
 * Read a value as a number, or refuse it.
 *
 * A blank string is refused explicitly because `Number("")` and `Number("  ")`
 * are both 0, which would make an empty body satisfy `less_than 1`. Non-finite
 * is refused for the same reason: `Infinity` is not a measurement.
 */
function toNumber(value: string | number): number | null {
    if (typeof value === "number") {
        return Number.isFinite(value) ? value : null;
    }

    if (value.trim() === "") {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * Whether two numbers are equal within {@link NUMERIC_EQUALITY_TOLERANCE}.
 *
 * The tolerance is relative to the larger magnitude, because that is the shape
 * of the error being tolerated: a double printed two ways differs in its last
 * bits, which scale with the value. The exact-equality short circuit is what
 * keeps `0 == 0` true, where a purely relative band would be zero wide.
 */
function numbersEqual(actual: number, expected: number): boolean {
    if (actual === expected) {
        return true;
    }

    const scale = Math.max(Math.abs(actual), Math.abs(expected));

    return Math.abs(actual - expected) <= NUMERIC_EQUALITY_TOLERANCE * scale;
}

/**
 * Compile an operator-supplied pattern, or refuse it.
 *
 * A pattern that no longer compiles is our stored configuration and never the
 * target's health, so the failure becomes a recorded `regex_invalid` outcome
 * instead of propagating: one bad pattern thrown from here would abort the whole
 * probe and publish a target that is up as unreachable. No flags, `g` least of
 * all: a global regexp carries `lastIndex` between calls, so the same rule would
 * answer differently on its second evaluation.
 */
function compilePattern(pattern: string): RegExp | null {
    try {
        return new RegExp(pattern);
    } catch {
        return null;
    }
}

/** Bound a recorded text value; see {@link OBSERVED_EXCERPT_CHARS}. */
function excerpt(value: string): string {
    return value.length > OBSERVED_EXCERPT_CHARS
        ? value.slice(0, OBSERVED_EXCERPT_CHARS)
        : value;
}

/** A rule that was measured, whichever way it came out. */
function measured(
    satisfied: boolean,
    rule: AssertionRule,
    observed: AssertionObserved,
): AssertionOutcome {
    return {
        verdict: satisfied ? "passed" : "failed",
        rule,
        observed,
    };
}

/** A rule that was not measured, and why. */
function skipped(
    reason: AssertionSkipReason,
    rule: AssertionRule | null,
    observed: AssertionObserved,
): AssertionOutcome {
    return {
        verdict: "skipped",
        rule,
        observed,
        reason,
    };
}
