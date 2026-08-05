<?php

namespace App\Support\Monitoring;

use App\Services\Monitoring\RelayClient;

/**
 * The save-time screen for a monitor's `assertion_rules`.
 *
 * WHY THIS EXISTS AT SAVE TIME AND NOT ONLY AT THE EDGE
 *
 * `assertion_rules` is stored as `jsonb` and forwarded verbatim
 * ({@see RelayClient::buildSpec()}). The edge evaluates
 * it and, by design (D2), SKIPS any rule it cannot evaluate and records the
 * reason instead of failing the check. That is the right behaviour there and it
 * is also why a rule with a typo is silent: the operator wrote it to break a
 * silence and gets a different silence back. This class is where a malformed rule
 * is refused, at the only place that can say something useful about it.
 *
 * THE TWO ERRORS ARE NOT SYMMETRIC
 *
 * A rule wrongly REFUSED here cannot be saved at all. A rule wrongly ALLOWED is
 * skipped at the edge and recorded as skipped. So where the two engines might
 * disagree, this screen allows what the edge can evaluate: a `name` on a
 * non-header rule is inert rather than wrong, a value beside `exists` is ignored
 * rather than rejected, and the string spelling of a number (`"200"`) is a legal
 * way to ask for a text comparison, not a mistake.
 *
 * THE VOCABULARY IS MIRRORED, NOT OWNED
 *
 * {@see self::TARGETS} and {@see self::OPERATORS} are the PHP side of a two-sided
 * mirror whose source of truth is the edge's own union:
 * `backend/workers/regional-checker/src/regional-probe.ts`, types
 * `AssertionTarget` and `AssertionOperator`, whose docblock names this directory
 * as the other side. Change one and the other must move with it, or the panel
 * accepts a rule the edge can only skip. They are enumerated ONCE here; nothing
 * else in PHP may hold a second copy.
 *
 * WHY A SIZE SCREEN IS HERE TOO, WHICH IS NOT A REDOS CONCERN AT ALL
 *
 * Every outcome the edge records echoes the rule VERBATIM, and the report travels
 * to the persisting job through the Redis `processing` queue, whose keys carry no
 * TTL and whose `volatile-lru` eviction victims are the check-persistence locks.
 * That is the exact traffic {@see CheckResult::toArray()} excludes the response
 * body to avoid, and a single `body contains` rule holding half a megabyte of
 * needle, or five hundred well-formed rules, walks it back in through the front
 * door. The edge cuts the OBSERVED value to 256 characters; nothing there cuts the
 * rule, so {@see self::RULE_MAX_BYTES} and {@see self::RULES_MAX_COUNT} are where
 * the bound lives, and the arithmetic they support is written out at
 * {@see CheckResult::$assertions}.
 *
 * WHAT THE REGEX SCREEN DOES AND DOES NOT PROVE (D5)
 *
 * `matches_regex` and `not_matches_regex` execute an operator-supplied, stored
 * pattern on JavaScript's backtracking RegExp at the edge, against a body of up to
 * a megabyte, inside a Durable Object that is ONE INSTANCE PER REGION shared
 * across tenants. The evaluator is synchronous, so the probe's timeout wrapper
 * cannot preempt it: a pattern that goes quadratic on that body does not fail one
 * check, it occupies the region. There is no RE2 there by decision, so this screen
 * is the actual containment, and it is a heuristic rather than a proof of
 * linear-time matching.
 *
 * Every claim below was MEASURED in node (V8, the engine that runs these at the
 * edge) over a growing adversarial subject, and anchored, so the number is the
 * pattern's own cost rather than the start-position sweep. A shape, not one
 * number: `AssertionRuleSetTest` carries the per-pattern figures.
 *
 * WHAT IT CATCHES
 *
 * - The 200-character cap, which applies to PATTERNS ONLY. A `body contains`
 *   value is compared with `String.includes`, which cannot backtrack, so it is
 *   bounded by the size screen above at twenty times that length and by nothing
 *   here: the two numbers answer different questions and only one of them is about
 *   what a match costs.
 * - {@see self::nestedQuantifierOffset()}: a repeating quantifier applied to a
 *   group whose body itself contains an unbounded one, and whose repetitions have
 *   no forced boundary (`(x+)+`, `(x*)*`, `([a-z]+)*`, `(x+x+)+y`, `((a)+)+`).
 *   This is the exponential family: `(a+)+b` runs 3.2ms at n=16 and 46.6s at
 *   n=32. {@see self::repeatIsPinned()} is the exemption that keeps a hostname
 *   (`^([a-z0-9-]+\.)+example\.com$`, flat at n=16,000) from being refused with
 *   it.
 * - {@see self::adjacentQuantifierOffset()}: two unbounded quantifiers reachable
 *   back to back over atoms that are not provably disjoint. This is the
 *   polynomial family, and it needs no nesting: `.*.*=.*`, the core of the
 *   pattern that took Cloudflare's edge down in 2019, is 4.0s at n=2,000 and
 *   32.0s at n=4,000, and `str_repeat('a*', 90).'b'` is 181 characters, inside
 *   the cap, and 82.8s at n=125. It follows the shape through a pair of brackets
 *   (`(.*)(.*)=.*` costs the same to the millisecond), past an element that can
 *   match nothing (`^\d*\.?\d*$` is quadratic where `^\d*\.\d*$` is flat), and
 *   past a mandatory element that both repeats can match anyway (`^[a-z]+.\w+$`
 *   is quadratic through a dot metacharacter), because all of those spellings cost
 *   the same at the edge and so must cost the same here.
 *
 * The two scans were checked against V8 over a differential corpus: 24 ordinary
 * monitoring patterns, all accepted and all measured flat, and 260 generated ones,
 * of which none that measured catastrophic was accepted.
 *
 * WHAT IT DOES NOT CATCH
 *
 * - Alternation overlap, and this is the largest gap by a wide margin, so its real
 *   magnitude is written out rather than illustrated with a mild example. A
 *   repeating group whose BRANCHES overlap has neither a nested quantifier nor two
 *   quantifiers in sequence, so both scans above pass it. `(a|aa)+b` is the polite
 *   version. The measured one is 60 single-character branches over the same
 *   character, `^(a|a|...|a)+z$`: **125 characters, inside every cap, ACCEPTED, and
 *   64.8 seconds in V8 at a subject of SIXTEEN characters**, with n=20 not
 *   returning inside a four-minute budget. Sixteen characters is shorter than the
 *   shortest body worth asserting on, so the 10 KiB pattern ceiling at the edge
 *   buys nothing against it, and the Durable Object it runs in is one instance per
 *   region shared across tenants.
 *
 *   What holds the risk down is that the only author is staff behind
 *   `User::canAccessPanel()`; nothing else does. Closing it is a KNOWN and
 *   affordable piece of work rather than the open research problem an earlier
 *   version of this docblock implied: refusing a repeating quantifier over a group
 *   whose top-level branches are not provably disjoint reuses
 *   {@see self::setsIntersect()} and {@see self::sequenceOutline()}, both already
 *   here. It is not done, and `AssertionRuleSetTest` pins BOTH shapes as accepted
 *   so that closing it flips a test rather than passing silently.
 * - The quadratic floor an unanchored pattern already pays for its
 *   start-position sweep: `\w+error` over a non-matching body is 94.4ms at
 *   n=8,000, and so is `[a-z]+9`. This screen removes what a pattern adds ON TOP
 *   of that floor. It cannot make an unanchored pattern linear, and the edge's
 *   body ceiling is what bounds the floor.
 * - Ambiguity that lives in the LANGUAGE of a multi-character group rather than
 *   in its first and last characters, which is where the character-set model
 *   stops. It costs refusals rather than misses: `(ab)+(ba)+c` is refused for the
 *   `b` the two repeats share although V8 measures it flat, and `(\w+\s+)+error`
 *   is refused for a body that ends in a repeat although it too measures flat
 *   anchored (its 229ms unanchored is the floor above, which `\w+error` pays
 *   identically). Both are pinned, because an over-refusal is a decision and not
 *   a surprise.
 * - A pattern whose two adjacent repeats have nothing mandatory after them, such
 *   as `.*[^a]*`, is refused although it cannot cost anything: it matches at the
 *   first position of every body, so the engine never backtracks. Refusing it is
 *   free in practice, because a rule that matches every body asserts nothing.
 * - Whatever the character-set model cannot name, which widens to EVERY character
 *   and can therefore only refuse more, never less. The one exception to that
 *   direction is {@see self::SPACE_CHARACTERS}, a listed set rather than a
 *   complement: it is ASCII-exact and does not enumerate the exotic Unicode space
 *   separators, so a pattern deliberately built from those could look disjoint
 *   when it is not.
 * - The trial compile proves the pattern compiles under PCRE, which is NOT the
 *   engine that will run it. The two diverge in both directions: PCRE accepts
 *   `\A`, `(?i)`, `(?>...)` and possessive quantifiers that V8 rejects (those
 *   reach the edge and become a recorded `regex_invalid` skip, never a false
 *   verdict), and V8 accepts variable-length lookbehind that PCRE rejects (those
 *   are refused here, which is the safe direction: the operator learns at save
 *   time instead of collecting silent skips).
 *
 * WHO CAN WRITE ONE, WHICH IS WHY A HEURISTIC IS DEFENSIBLE AT ALL
 *
 * Today the only writer is the staff Filament panel behind
 * `User::canAccessPanel()`: no API `FormRequest` names `assertion_rules`, and
 * every API monitor write goes through `$request->validated()`. So the actor is
 * staff and not a tenant, which is what makes a documented heuristic an
 * acceptable posture rather than a hole. Wiring `assertion_rules` into a
 * `FormRequest` (which {@see self::problems()} invites below, and which is the
 * right shape for it) makes the actor a tenant, and this analysis has to be
 * redone before that lands.
 *
 * WHERE A CALLER PLUGS IN
 *
 * {@see self::problems()} takes the DECODED value and returns Laravel validation
 * messages carrying the `:attribute` placeholder, so a caller hands each one to
 * `$fail()` unchanged. `MonitorForm`'s code editor is the only caller today; an
 * API `FormRequest` can call the same method without going through Filament.
 */
class AssertionRuleSet
{
    /**
     * What an assertion may be evaluated against.
     *
     * @var list<string>
     */
    public const array TARGETS = [
        'status_code',
        'response_time_ms',
        'body',
        'header',
    ];

    /**
     * How an assertion compares its target against its value.
     *
     * @var list<string>
     */
    public const array OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'greater_than',
        'less_than',
        'matches_regex',
        'not_matches_regex',
        'exists',
        'not_exists',
    ];

    /**
     * The longest pattern an operator may store, in characters.
     *
     * A bound on the search space rather than a safety proof: a catastrophic
     * pattern is usually short, so this is one leg of the heuristic and not the
     * whole of it. See the class docblock.
     */
    public const int PATTERN_MAX_CHARS = 200;

    /**
     * The largest one rule may be, as the JSON it is stored and echoed as.
     *
     * A different question from {@see self::PATTERN_MAX_CHARS} with a different
     * answer, and the gap between 200 and 4096 is the whole distinction: that cap
     * bounds what a pattern may COST when the edge matches it, so it is as tight as
     * a real pattern tolerates, while this one bounds what a rule WEIGHS when the
     * edge echoes it, so it is as loose as a real body needle needs. Neither
     * substitutes for the other, and a pattern is screened by both.
     *
     * 4096 bytes is roughly a printed page of text, which is far past any needle an
     * operator writes against a body and far short of the megabyte
     * {@see CheckResult::$assertions} has to stay away from.
     * An operator wanting to compare a whole document writes `contains` on a
     * distinctive fragment of it instead, which is the better rule anyway.
     *
     * Measured over the WHOLE rule and not over its `value`, because `value` is not
     * the only field the edge echoes: a `name` on a non-header rule is inert rather
     * than wrong, an unknown key is tolerated for the same reason, and both ride
     * into `assertion_results` verbatim. A cap on `value` alone would leave the same
     * bulk reachable through a field nothing validates. Bytes and not characters
     * because bytes are what Redis holds.
     */
    public const int RULE_MAX_BYTES = 4096;

    /**
     * The most rules one monitor may carry.
     *
     * {@see self::RULE_MAX_BYTES} bounds nothing on its own: the report is the sum
     * of its outcomes, so 500 well-formed rules are 500 echoed rules on every check
     * from every region. The two together are what make the arithmetic in
     * {@see CheckResult::$assertions} hold.
     *
     * 20 is the most permissive number in the surveyed products (Datadog caps
     * assertions at 20, UptimeRobot at 5), so it refuses nothing a competitor
     * allows, and a monitor needing more is a monitor that should be split.
     */
    public const int RULES_MAX_COUNT = 20;

    /**
     * How the rule is encoded to be measured.
     *
     * Slashes and non-ASCII stay unescaped so the measurement is of the text an
     * operator actually wrote rather than of `\/` and `\uXXXX` expansions; the
     * substitution flag is what keeps `json_encode` from answering `false` on a
     * broken byte sequence, which is reachable from a caller that hands this class
     * a PHP array rather than a `json_decode` result.
     */
    protected const int RULE_ENCODE_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * The widest character range the class reader will expand rather than widen to
     * every character.
     *
     * A bound on the work one save-time scan may do. A range above it is a range
     * whose complement is the smaller half anyway, and widening is the refusing
     * direction, so nothing unsafe follows from the ceiling.
     */
    protected const int WIDEST_EXPANDED_RANGE = 4096;

    /** What `\d` matches, and `\D` the complement of. */
    protected const string DIGIT_CHARACTERS = '0123456789';

    /** What `\w` matches, and `\W` the complement of. */
    protected const string WORD_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    /**
     * What `\s` matches, and `\S` the complement of.
     *
     * ASCII-exact and Unicode-approximate: the two common non-ASCII spaces are
     * here and the exotic separators (U+1680, the U+2000 block, U+3000) are not,
     * which is the one place this model can under-state a set rather than
     * over-state one. See the class docblock.
     */
    protected const string SPACE_CHARACTERS = " \t\n\r\f\v\u{a0}\u{feff}";

    /**
     * The targets whose reading is a number, so a comparison against a value
     * that is not one can only ever answer the same way on every check.
     *
     * @var list<string>
     */
    protected const array NUMERIC_TARGETS = [
        'status_code',
        'response_time_ms',
    ];

    /** The two operators that measure presence and read no value at all. */
    protected const array PRESENCE_OPERATORS = [
        'exists',
        'not_exists',
    ];

    /** The two operators whose value is a bound and must therefore be a number. */
    protected const array ORDERING_OPERATORS = [
        'greater_than',
        'less_than',
    ];

    /** The two operators whose value is an operator-supplied pattern. */
    protected const array PATTERN_OPERATORS = [
        'matches_regex',
        'not_matches_regex',
    ];

    /**
     * Characters tried as the delimiter of the trial compile, in order.
     *
     * A JS pattern carries no delimiters, so PCRE needs one wrapped around it,
     * and escaping an occurrence is not an option: a pattern that already
     * contains `\/` would become `\\/`, which means something else. So a
     * character the pattern does not contain is used instead. `\x01` is the last
     * resort because no operator types a control character.
     *
     * @var list<string>
     */
    protected const array TRIAL_DELIMITERS = [
        '/',
        '#',
        '~',
        '%',
        '!',
        '@',
        ';',
        ',',
        '=',
        '&',
        ':',
        "\x01",
    ];

    /**
     * Every reason this rule set cannot be saved, one per offending rule.
     *
     * @param  mixed  $rules  The DECODED value: an array of rules, or null when
     *                        the monitor asserts nothing.
     * @return list<string> Laravel validation messages carrying `:attribute`,
     *                      empty when the set is valid.
     */
    public static function problems(mixed $rules): array
    {
        // A monitor that asserts nothing is a valid monitor, and the edge reads
        // the same absence as "not evaluated" rather than "all passed" (D4).
        if ($rules === null) {
            return [];
        }

        /*
         * `array_is_list` and not merely `is_array`: the edge guards with
         * `Array.isArray`, so a JSON object at the top level is a rule set that
         * is never iterated at all.
         *
         * The one shape this cannot see is a JSON object with numeric keys
         * (`{"0": {...}}`), which `json_decode(..., true)` produces as a list
         * and the edge treats as an object. It is accepted here and produces NO
         * REPORT there: `evaluateAssertions()` answers null on anything
         * `Array.isArray` refuses, which records the check as asserting nothing
         * rather than as a skip (a skip is a per-rule `value_invalid` verdict
         * INSIDE a report, and there is no report here). `assertions_passed`
         * therefore stays null, which is the allowed direction of the asymmetry
         * above: silence, never a pass nobody measured.
         */
        if (! is_array($rules) || ! array_is_list($rules)) {
            return ['The :attribute must be a JSON array of assertion rules.'];
        }

        /*
         * The count, before any rule is looked at. ONE message and not one per rule
         * past the limit: a set that does not fit is a single fault, it has to be
         * fixed before any per-rule sentence is worth reading, and 481 messages
         * under a form field is not a message at all.
         */
        if (count($rules) > self::RULES_MAX_COUNT) {
            return [
                static::at(self::RULES_MAX_COUNT).'is past the limit of '.self::RULES_MAX_COUNT
                    .' rules per monitor. Every rule is echoed into the recorded outcome on every check'
                    .' from every region; split the monitor instead.',
            ];
        }

        $problems = [];

        foreach ($rules as $index => $rule) {
            $problem = static::ruleProblem($index, $rule);

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * The first reason one rule cannot be saved, or null when it can.
     *
     * The FIRST and not all of them: a rule whose target is unknown has nothing
     * useful to say about its value, and an operator fixing one line at a time
     * reads one sentence better than four.
     *
     * @param  int  $index  The rule's position, which is its only identity in
     *                      both the editor and the stored outcome.
     */
    protected static function ruleProblem(int $index, mixed $rule): ?string
    {
        // 1. A rule is a JSON object. A scalar or a nested array in this
        //    position names nothing the edge can read.
        if (! is_array($rule) || array_is_list($rule)) {
            return static::at($index).'must be a JSON object with a target and an operator.';
        }

        // 2. The rule's own weight, before anything is read out of it. Second
        //    because it is the only check that can be answered without knowing
        //    whether the rule means anything, and because every message below
        //    quotes a field this one may find to be half a megabyte long.
        $bytes = strlen((string) json_encode($rule, self::RULE_ENCODE_FLAGS));

        if ($bytes > self::RULE_MAX_BYTES) {
            return static::at($index)."is {$bytes} bytes of JSON; the limit is ".self::RULE_MAX_BYTES
                .' bytes, because the edge echoes the whole rule into the outcome it records for every'
                .' check. Assert on a distinctive fragment rather than on a whole document.';
        }

        // 3. The vocabulary, mirrored from the edge's union.
        $target = $rule['target'] ?? null;
        if (! is_string($target) || trim($target) === '') {
            return static::at($index).'is missing a target. Known targets: '.static::listOf(self::TARGETS).'.';
        }
        if (! in_array($target, self::TARGETS, true)) {
            return static::at($index).'names an unknown target '.static::describe($target)
                .'. Known targets: '.static::listOf(self::TARGETS).'.';
        }

        $operator = $rule['operator'] ?? null;
        if (! is_string($operator) || trim($operator) === '') {
            return static::at($index).'is missing an operator. Known operators: '.static::listOf(self::OPERATORS).'.';
        }
        if (! in_array($operator, self::OPERATORS, true)) {
            return static::at($index).'names an unknown operator '.static::describe($operator)
                .'. Known operators: '.static::listOf(self::OPERATORS).'.';
        }

        // 4. A `header` rule addresses one header by name. All three shapes the
        //    editor can produce (the key omitted, an explicit null, a field left
        //    blank) are the same fault, and the edge skips every one of them.
        if ($target === 'header') {
            $name = $rule['name'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                return static::at($index).'targets a header without naming one; add a "name" field.';
            }
        }

        // 5. Presence operators read no value, so a leftover one beside them is
        //    inert at the edge rather than wrong.
        if (in_array($operator, self::PRESENCE_OPERATORS, true)) {
            return null;
        }

        // 6. The value the comparison needs. An absent key, an explicit null, a
        //    boolean and a nested structure are one fault: each reaches the edge
        //    as `value_invalid`.
        $value = $rule['value'] ?? null;
        if ($value === null) {
            return static::at($index)."needs a value for the [{$operator}] operator.";
        }
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return static::at($index)."needs a string or number value for the [{$operator}] operator, not "
                .static::describe($value).'.';
        }

        // 7. A comparison that can never answer, or can only ever answer one
        //    way. An ordering over a non-number is unanswerable at the edge and
        //    skipped there; equality against a numeric target with a value that
        //    is not a number is answerable and constant, which is worse, because
        //    it is a rule that looks like it is watching something.
        if (static::comparesNumerically($target, $operator) && static::asNumber($value) === null) {
            return static::at($index)."compares [{$target}] with [{$operator}], so its value must be a number; got "
                .static::describe($value).'.';
        }

        // 8. The regex screen (D5). Last, because the seven checks above are
        //    cheap and a pattern is the only value that costs anything to look
        //    at. A pattern is screened by both caps: this one bounds what it may
        //    cost, the byte cap above bounds what it may weigh, and the two
        //    numbers are far apart on purpose.
        if (in_array($operator, self::PATTERN_OPERATORS, true)) {
            return static::patternProblem($index, (string) $value);
        }

        return null;
    }

    /**
     * Whether the comparison reads its value as a number.
     *
     * The ordering operators always do. `equals` / `not_equals` follow the kind
     * of the value the operator wrote, which is why they are only required to be
     * numeric against a numeric TARGET: `status_code equals "OK"` is a text
     * comparison against `"200"` that fails on every check forever, while
     * `body equals "OK"` is exactly what an operator meant. The four text
     * operators are never numeric, which is what keeps the blessed
     * `status_code matches_regex ^(200|302)$` spelling legal.
     */
    protected static function comparesNumerically(string $target, string $operator): bool
    {
        if (in_array($operator, self::ORDERING_OPERATORS, true)) {
            return true;
        }

        return in_array($target, self::NUMERIC_TARGETS, true)
            && in_array($operator, ['equals', 'not_equals'], true);
    }

    /**
     * Read a value as the edge's `toNumber` would, or refuse it.
     *
     * `is_numeric` over the trimmed string rather than a cast, because the edge
     * refuses a blank string for the same reason: `Number("")` is 0, which would
     * make an empty body satisfy `less_than 1`. The two parsers agree on every
     * decimal form; they diverge only on JS-specific spellings such as `0x1A`,
     * which is refused here and which nobody writes as a latency bound.
     */
    protected static function asNumber(string|int|float $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        $trimmed = trim($value);

        return is_numeric($trimmed) && is_finite((float) $trimmed) ? (float) $trimmed : null;
    }

    /**
     * The reason a pattern may not be stored, or null when it may.
     *
     * The order is the cheap-and-decisive one: a pattern over the cap is refused
     * without being scanned, the two structural scans run on a bounded string, and
     * only then is PCRE asked to compile it. Nesting is asked about first because
     * it is the more expensive family (exponential rather than polynomial) and
     * because a pattern that carries both reads better named by its outer shape.
     */
    protected static function patternProblem(int $index, string $pattern): ?string
    {
        $characters = mb_strlen($pattern);

        if ($characters > self::PATTERN_MAX_CHARS) {
            return static::at($index)."has a {$characters}-character pattern; the limit is "
                .self::PATTERN_MAX_CHARS.' characters, because the edge matches it with a backtracking engine.';
        }

        $offset = static::nestedQuantifierOffset($pattern);

        if ($offset !== null) {
            return static::at($index)."repeats a group whose body already repeats, at offset {$offset} of "
                .static::describe($pattern).'. That nested quantifier can take exponential time to match at the'
                .' edge; rewrite it with a bounded repeat, or end the repeated group on a separator it cannot'
                .' match itself.';
        }

        $offset = static::adjacentQuantifierOffset($pattern);

        if ($offset !== null) {
            return static::at($index)."runs two unbounded quantifiers in sequence, at offset {$offset} of "
                .static::describe($pattern).'. Nothing between them decides where the first repeat stops and the'
                .' second starts, so a body that fails the match is retried at every split; separate them with a'
                .' character neither can match, or bound one of the repeats.';
        }

        $error = static::compileError($pattern);

        if ($error !== null) {
            return static::at($index).'has a pattern that does not compile: '.$error.'.';
        }

        return null;
    }

    /**
     * The offset of a repeating quantifier applied to a group whose body already
     * contains an unbounded one and whose repetitions have no forced boundary, or
     * null when the pattern carries none.
     *
     * A hand-written scan and not a regex over the pattern, because the three
     * things that decide the answer are structural: an escape makes the next
     * character a literal, a character class makes every quantifier inside it a
     * member, and a group's flag has to survive nesting. The scan reads the
     * pattern with V8's class rules (a leading `]` closes an empty class), since
     * V8 is the engine that will run it.
     *
     * The flag propagates to EVERY open group rather than the innermost, which is
     * what makes `((a+))+` a hit: the offending repeat is not a direct child of
     * the group the outer quantifier applies to.
     *
     * {@see self::repeatIsPinned()} is the exemption, and it is the difference
     * between refusing a shape and refusing a hostname.
     */
    protected static function nestedQuantifierOffset(string $pattern): ?int
    {
        $length = strlen($pattern);
        $frames = [];
        $offset = 0;

        while ($offset < $length) {
            $character = $pattern[$offset];

            if ($character === '\\') {
                $offset += 2;

                continue;
            }

            if ($character === '[') {
                $offset = static::endOfClass($pattern, $offset);

                continue;
            }

            if ($character === '(') {
                // The body offset travels with the frame, because the question the
                // exemption asks at the closing bracket is about the whole body
                // and the scan has already walked past it by then.
                $body = static::groupBodyStart($pattern, $offset);
                $frames[] = ['repeats' => false, 'body' => $body];
                $offset = $body;

                continue;
            }

            if ($character === ')') {
                $frame = array_pop($frames) ?? ['repeats' => false, 'body' => $offset];
                $quantifier = static::quantifierAt($pattern, $offset + 1);

                if ($frame['repeats'] && $quantifier !== null && $quantifier['repeating']
                    && ! static::repeatIsPinned($pattern, $frame['body'], $offset)) {
                    return $offset + 1;
                }

                // The quantifier itself is left to the next iteration, which is
                // what marks the ENCLOSING groups. `((a)+)+` needs that: the
                // inner group's own repeat is the outer group's body repeat.
                $offset++;

                continue;
            }

            $quantifier = static::quantifierAt($pattern, $offset);

            if ($quantifier !== null) {
                // `repeating`, not `unbounded`. A BOUNDED inner repeat with a
                // maximum of two or more multiplies the search space just as an
                // unbounded one does, and reading only `unbounded` here let the
                // whole `{m,n}` family through. Measured in V8: `([a-zA-Z]{2,4})+$`
                // is 103 ms at n=44, 479 ms at 48, 2.21 s at 52, 10.2 s at 56, so
                // roughly 4.6x per four characters of subject. It is also the tail
                // of the most-pasted email regex on the internet, which the screen
                // therefore accepted in full. `?` and `{0,1}` are not repeating and
                // still do not mark a body, which is what keeps `(https?://)+`
                // acceptable.
                if ($quantifier['variable']) {
                    foreach (array_keys($frames) as $open) {
                        $frames[$open]['repeats'] = true;
                    }
                }

                $offset += $quantifier['length'];

                continue;
            }

            $offset++;
        }

        return null;
    }

    /**
     * Whether every repetition of this group body has a boundary the engine cannot
     * put anywhere else, which makes the repeat deterministic however many times
     * it runs and however much its body repeats inside.
     *
     * The argument is a counting one. If every repetition ends on one character
     * that appears nowhere else in the body, then the boundaries of `X` repeated
     * are exactly the positions of that character, so there is one decomposition
     * and nothing to backtrack across. `(\d+\.)+` is that shape, and so is
     * `([a-z0-9-]+\.)+`, which is the natural spelling of a hostname and the rule
     * an operator writes against a `Location` header. Both are flat in V8 at
     * n=16,000 while the scan above refused them, and a refused rule is one an
     * operator cannot save at all.
     *
     * The head form is the same argument mirrored: `\d+(\.\d+)+` puts the one
     * character at the START of each repetition, which pins the boundaries just as
     * well, and that is how a version string is written.
     *
     * The exemption is narrow on purpose. The separator has to run exactly once
     * (`(\w+\s+)+` ends in a repeat, so nothing pins where one repetition's
     * whitespace stops and the next one's begins) and the body may not branch (one
     * branch's separator says nothing about another's). It is also not the whole
     * containment: the body BETWEEN two pinned boundaries can be ambiguous on its
     * own, and {@see self::adjacentQuantifierOffset()} is what refuses that, which
     * is why `(\d+\d+\.)+` is still refused.
     */
    protected static function repeatIsPinned(string $pattern, int $start, int $end): bool
    {
        $outline = static::sequenceOutline($pattern, $start, $end);

        if ($outline['alternation']) {
            return false;
        }

        $first = $outline['first'];
        $last = $outline['last'];

        if ($first !== null && $first['lone']
            && ! static::setsIntersect($first['chars'], static::charactersIn($pattern, $first['end'], $end))) {
            return true;
        }

        return $last !== null && $last['lone']
            && ! static::setsIntersect($last['chars'], static::charactersIn($pattern, $start, $last['start']));
    }

    /**
     * The offset of the second of two unbounded quantifiers that nothing between
     * them separates, or null when the pattern carries no such pair.
     *
     * This is the family the screen missed, and it needs no nesting at all: two
     * unbounded repeats reachable back to back over atoms that can match the same
     * character give the engine one distinct path per split point, which is a
     * factor of the subject length for each repeat beyond the first. `.*.*=.*`, the
     * core of the pattern that took Cloudflare's edge down in 2019, is cubic in
     * V8 (503ms at n=1,000, 4.0s at n=2,000, 32.0s at n=4,000) against a body the
     * edge allows a megabyte of.
     *
     * "Adjacent" is decided structurally rather than textually, because two
     * spellings of the same thing cost the same at the edge and must cost the same
     * here: a pair of brackets around either repeat is not a separator
     * (`(.*)(.*)=.*` measures 4.0s at n=2,000, the same to the millisecond as the
     * pattern without them), and neither is an element that can match nothing
     * (`^\d*\.?\d*$` is quadratic where `^\d*\.\d*$` is flat).
     */
    protected static function adjacentQuantifierOffset(string $pattern): ?int
    {
        $pending = [];

        return static::adjacentQuantifierIn($pattern, 0, strlen($pattern), $pending);
    }

    /**
     * The same question asked of one sequence, which is the pattern itself or the
     * body of one group.
     *
     * `$pending` is the chain: the unbounded repeats reached so far with nothing
     * mandatory since. By reference, because an unquantified group is transparent
     * and its body continues this very chain rather than starting one.
     *
     * @param  list<array<string, mixed>>  $pending  Elements as {@see self::elementAt()} returns them.
     */
    protected static function adjacentQuantifierIn(string $pattern, int $start, int $end, array &$pending): ?int
    {
        $offset = $start;
        $branched = false;

        while ($offset < $end) {
            $character = $pattern[$offset];

            // A branch is a sequence of its own: what the chain reached before the
            // `|` is not what the next branch starts from, and which branch
            // matched decides what reaches whatever follows the sequence, so a
            // sequence that branched carries nothing out of itself either.
            if ($character === '|') {
                $pending = [];
                $branched = true;
                $offset++;

                continue;
            }

            // An anchor consumes nothing, so it can neither be absorbed by a
            // repeat nor pin a boundary between two of them.
            if ($character === '^' || $character === '$') {
                $offset++;

                continue;
            }

            if ($character === '(') {
                $close = static::endOfGroup($pattern, $offset);
                $body = static::groupBodyStart($pattern, $offset);
                $quantifier = static::quantifierAt($pattern, $close + 1);
                $found = static::adjacentQuantifierAtGroup($pattern, $offset, $close, $body, $quantifier, $pending);

                if ($found !== null) {
                    return $found;
                }

                $offset = $close + 1 + ($quantifier === null ? 0 : $quantifier['length']);

                continue;
            }

            $element = static::elementAt($pattern, $offset);

            if ($element === null) {
                // A stray quantifier or an unmatched bracket: the trial compile is
                // what refuses those, and this scan must not read one as an atom.
                $offset++;

                continue;
            }

            $found = static::chainStep($element, $pending);

            if ($found !== null) {
                return $found;
            }

            $offset = $element['end'];
        }

        if ($branched) {
            $pending = [];
        }

        return null;
    }

    /**
     * The three kinds of bracket, which differ in what they do to the chain.
     *
     * A lookaround consumes nothing, so its body is neither adjacent to what comes
     * before it nor to what comes after. A quantified group is one atom of the
     * enclosing sequence and its body is a sequence of its own, so the chain
     * reaches into it and what happens inside does not reach back out. An
     * unquantified group is a pair of brackets and nothing else, so the body IS the
     * enclosing sequence and the chain runs straight through it: that is what keeps
     * `(.*)(.*)=.*`, which costs what `.*.*=.*` costs to the millisecond, from
     * being a way around the scan.
     *
     * Only the quantified case needs the group summarised, and summarising walks
     * the whole body: asking for it in the transparent case too would make a
     * pattern of nested brackets cost the square of its depth.
     *
     * @param  array{length: int, repeating: bool, unbounded: bool, variable: bool, mandatory: bool}|null  $quantifier
     * @param  list<array<string, mixed>>  $pending
     */
    protected static function adjacentQuantifierAtGroup(
        string $pattern,
        int $offset,
        int $close,
        int $body,
        ?array $quantifier,
        array &$pending,
    ): ?int {
        if (static::isLookaround($pattern, $offset)) {
            $inside = [];

            return static::adjacentQuantifierIn($pattern, $body, $close, $inside);
        }

        if ($quantifier === null) {
            return static::adjacentQuantifierIn($pattern, $body, $close, $pending);
        }

        $inside = $pending;
        $found = static::adjacentQuantifierIn($pattern, $body, $close, $inside);

        if ($found !== null) {
            return $found;
        }

        $element = static::elementAt($pattern, $offset);

        return $element === null ? null : static::chainStep($element, $pending);
    }

    /**
     * Fold one element into the chain, answering with its offset when it closes an
     * unseparated pair.
     *
     * A chain entry is an element as {@see self::elementAt()} returns it plus a
     * `crossed` list: the mandatory elements passed since, which is what
     * {@see self::boundaryIsPinned()} needs to answer for a separator that only one
     * of the two repeats can match.
     *
     * @param  array<string, mixed>  $element
     * @param  list<array<string, mixed>>  $pending
     */
    protected static function chainStep(array $element, array &$pending): ?int
    {
        // 1. Two unbounded repeats the engine can reach back to back. Unless one
        //    of them cannot match the character the other's boundary falls on,
        //    every split between them is a separate path to try.
        if ($element['unbounded']) {
            foreach ($pending as $left) {
                if (! static::boundaryIsPinned($left, $element)) {
                    return $element['start'];
                }
            }
        }

        // 2. A mandatory element only pins the repeats it cannot stand in for.
        //    `\d*\.\d*` is separated by a full stop no `\d` can match and is flat;
        //    `[a-z]+.\w+` is separated by a dot metacharacter both sides can match
        //    and V8 measures it quadratic, so that repeat stays in the chain. The
        //    separator is remembered either way, because it may still pin the pair
        //    from the other side.
        if ($element['mandatory']) {
            $crossing = [];

            foreach ($pending as $left) {
                if (static::setsIntersect($left['chars'], $element['chars'])) {
                    $left['crossed'][] = $element['chars'];
                    $crossing[] = $left;
                }
            }

            $pending = $crossing;
        }

        // 3. Only an unbounded repeat can absorb its neighbour's characters; a
        //    bounded one costs a fixed small factor, as `(\d{3})+` does.
        if ($element['unbounded']) {
            $element['crossed'] = [];
            $pending[] = $element;
        }

        return null;
    }

    /**
     * Whether the boundary between two unbounded repeats can only fall in one
     * place, which is what makes their concatenation unambiguous.
     *
     * Three sufficient conditions, each a contradiction argument, and any one of
     * them is enough. If the left repeat always ends on a character the right one
     * cannot match, moving the boundary would put that character inside the right
     * repeat's match; if the right repeat always begins on a character the left one
     * cannot match, the mirror holds; and a mandatory element crossed between the
     * two does the same job as soon as ONE of them cannot spell it, because two
     * parses would then have to disagree about where a character sits that only one
     * side can write. That is what separates `(\d+\.)+\d+`, `\d+(\.\d+)+` and
     * `[a-z0-9.-]+\.[a-z]{2,}` (all flat) from `\d+\d+`, `.*.*` and `[a-z]+.\w+`
     * (all quadratic).
     *
     * The sets are over-approximations, so an unknown widens to every character
     * and the answer falls to "not pinned", which refuses. The reverse mistake,
     * calling a boundary pinned when it is not, is the one this must not make.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    protected static function boundaryIsPinned(array $left, array $right): bool
    {
        if (! static::setsIntersect($left['tail'], $right['chars'])
            || ! static::setsIntersect($left['chars'], $right['head'])) {
            return true;
        }

        foreach ($left['crossed'] as $separator) {
            if (! static::setsIntersect($separator, $right['chars'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The quantifier at an offset, or null when there is none there.
     *
     * `repeating` answers "can this run its atom more than once", which is what
     * makes an outer quantifier dangerous, so `?` is excluded from it: one
     * optional pass cannot blow up. `unbounded` answers "can this run its atom
     * without a ceiling", which is what makes an INNER quantifier dangerous, so a
     * bounded inner repeat such as `(\d{3})+` is left alone: it is polynomial in
     * a fixed, small factor, not exponential. `mandatory` answers "does this run
     * its atom at least once", which is what decides whether the element can pin
     * the boundary between two repeats: `\.` and `\.+` can, `\.?` and `\.*`
     * cannot, and V8 measures the difference (`^\d*\.\d*$` is flat where
     * `^\d*\.?\d*$` is quadratic).
     *
     * @return array{length: int, repeating: bool, unbounded: bool, variable: bool, mandatory: bool}|null
     */
    protected static function quantifierAt(string $pattern, int $offset): ?array
    {
        $length = strlen($pattern);

        if ($offset >= $length) {
            return null;
        }

        $character = $pattern[$offset];
        $width = 1;
        $repeating = true;
        $unbounded = true;
        $variable = true;
        $mandatory = $character === '+';

        if ($character === '?') {
            $repeating = false;
            $unbounded = false;
            $variable = false;
        } elseif ($character === '{') {
            $closing = strpos($pattern, '}', $offset);

            // A `{` that does not close, or does not hold a count, is a literal
            // brace and not a quantifier at all; V8 reads it that way too.
            if ($closing === false) {
                return null;
            }

            $counts = substr($pattern, $offset + 1, $closing - $offset - 1);

            if (preg_match('/^(\d+)(,(\d*))?$/', $counts, $bounds) !== 1) {
                return null;
            }

            $width = $closing - $offset + 1;
            $unbounded = isset($bounds[2]) && $bounds[3] === '';
            $maximum = $unbounded
                ? null
                : (int) (($bounds[3] ?? '') === '' ? $bounds[1] : $bounds[3]);
            $repeating = $unbounded || $maximum >= 2;
            $mandatory = (int) $bounds[1] >= 1;
            // An EXACT count is not ambiguous: `{3}` has one way to match three
            // characters, so `(\d{3})+` splits a digit run deterministically and is
            // linear. A RANGE is where the engine can try several lengths for the
            // same span, which is what multiplies the search space. That is the
            // distinction {@see self::nestedQuantifierOffset()} needs, and reading
            // `repeating` there instead refused `(\d{3})+`.
            $variable = $unbounded || $maximum > (int) $bounds[1];
        } elseif ($character !== '+' && $character !== '*') {
            return null;
        }

        // A lazy `?` or a possessive `+` belongs to the quantifier it follows,
        // and neither changes what it can do to the search space.
        if ($offset + $width < $length && ($pattern[$offset + $width] === '?' || $pattern[$offset + $width] === '+')) {
            $width++;
        }

        return [
            'length' => $width,
            'repeating' => $repeating,
            'unbounded' => $unbounded,
            'variable' => $variable,
            'mandatory' => $mandatory,
        ];
    }

    /** The offset just past the character class that starts at `$offset`. */
    protected static function endOfClass(string $pattern, int $offset): int
    {
        $length = strlen($pattern);
        $offset++;

        if ($offset < $length && $pattern[$offset] === '^') {
            $offset++;
        }

        while ($offset < $length) {
            if ($pattern[$offset] === '\\') {
                $offset += 2;

                continue;
            }

            if ($pattern[$offset] === ']') {
                return $offset + 1;
            }

            $offset++;
        }

        // An unterminated class: the trial compile is what refuses it.
        return $length;
    }

    /** The offset of the `)` closing the group at `$offset`, or the pattern's end. */
    protected static function endOfGroup(string $pattern, int $offset): int
    {
        $length = strlen($pattern);
        $depth = 0;

        while ($offset < $length) {
            $character = $pattern[$offset];

            if ($character === '\\') {
                $offset += 2;

                continue;
            }

            if ($character === '[') {
                $offset = static::endOfClass($pattern, $offset);

                continue;
            }

            if ($character === '(') {
                $depth++;
                $offset++;

                continue;
            }

            if ($character === ')') {
                $depth--;

                if ($depth === 0) {
                    return $offset;
                }
            }

            $offset++;
        }

        // An unterminated group: the trial compile is what refuses it.
        return $length;
    }

    /**
     * The offset of the first character of the group opening at `$offset`, past
     * whatever the brackets declare themselves to be.
     *
     * A group's prefix is not part of its body and must not be read as one, or the
     * `:` of `(?:` becomes a character the body can match and the name of
     * `(?<host>...)` becomes six of them.
     */
    protected static function groupBodyStart(string $pattern, int $offset): int
    {
        $length = strlen($pattern);

        if ($offset + 1 >= $length || $pattern[$offset + 1] !== '?') {
            return $offset + 1;
        }

        $marker = $offset + 2 < $length ? $pattern[$offset + 2] : '';

        if ($marker === ':' || $marker === '=' || $marker === '!') {
            return $offset + 3;
        }

        if ($marker !== '<') {
            // A `?` that opens nothing: the trial compile refuses it, and until
            // then it is read as one more character of the body.
            return $offset + 1;
        }

        $behind = $offset + 3 < $length ? $pattern[$offset + 3] : '';

        if ($behind === '=' || $behind === '!') {
            return $offset + 4;
        }

        $closing = strpos($pattern, '>', $offset + 3);

        return $closing === false ? $offset + 3 : $closing + 1;
    }

    /** Whether the group at `$offset` asserts rather than consumes. */
    protected static function isLookaround(string $pattern, int $offset): bool
    {
        return in_array(substr($pattern, $offset + 1, 2), ['?=', '?!'], true)
            || in_array(substr($pattern, $offset + 1, 3), ['?<=', '?<!'], true);
    }

    /**
     * One element of a sequence: an atom or a group, together with the quantifier
     * that belongs to it, or null when what sits at the offset is neither.
     *
     * The keys are what the two scans ask about a position in a pattern:
     * `mandatory` (does it always consume at least one character), `unbounded`
     * (can it run without a ceiling), `lone` (is it exactly one character, run
     * exactly once, which is what a separator has to be), and the three character
     * sets, where `head` and `tail` are what a match of it can begin and end with.
     *
     * @return array{
     *     start: int,
     *     end: int,
     *     lone: bool,
     *     mandatory: bool,
     *     unbounded: bool,
     *     chars: array{negated: bool, chars: list<string>},
     *     head: array{negated: bool, chars: list<string>},
     *     tail: array{negated: bool, chars: list<string>},
     * }|null
     */
    protected static function elementAt(string $pattern, int $offset): ?array
    {
        if ($pattern[$offset] === '(') {
            $close = static::endOfGroup($pattern, $offset);
            $quantifier = static::quantifierAt($pattern, $close + 1);
            $lookaround = static::isLookaround($pattern, $offset);
            $outline = static::sequenceOutline($pattern, static::groupBodyStart($pattern, $offset), $close);
            $consumes = $lookaround ? static::noCharacter() : $outline['chars'];

            return [
                'start' => $offset,
                'end' => $close + 1 + ($quantifier === null ? 0 : $quantifier['length']),
                // Never one character: a group is a sequence, so it can never be
                // the separator that pins a boundary.
                'lone' => false,
                'mandatory' => ! $lookaround && $outline['mandatory']
                    && ($quantifier === null || $quantifier['mandatory']),
                'unbounded' => ! $lookaround && $quantifier !== null && $quantifier['unbounded'],
                'chars' => $consumes,
                'head' => $lookaround ? $consumes : $outline['head'],
                'tail' => $lookaround ? $consumes : $outline['tail'],
            ];
        }

        $atom = static::atomAt($pattern, $offset);

        if ($atom === null) {
            return null;
        }

        $quantifier = static::quantifierAt($pattern, $offset + $atom['length']);

        return [
            'start' => $offset,
            'end' => $offset + $atom['length'] + ($quantifier === null ? 0 : $quantifier['length']),
            'lone' => $atom['single'] && $quantifier === null,
            'mandatory' => $atom['single'] && ($quantifier === null || $quantifier['mandatory']),
            'unbounded' => $quantifier !== null && $quantifier['unbounded'],
            'chars' => $atom['chars'],
            'head' => $atom['chars'],
            'tail' => $atom['chars'],
        ];
    }

    /**
     * What one sequence of elements looks like from the outside.
     *
     * `head` and `tail` over-approximate the characters a match of the whole
     * sequence can begin and end with. They narrow to the first or last element
     * only when that element always runs, since an optional one leaves the edge
     * open, and only when the sequence does not branch, since a branch means it has
     * more than one shape; otherwise they widen to every character the sequence
     * mentions, which is a superset either way.
     *
     * @return array{
     *     alternation: bool,
     *     mandatory: bool,
     *     chars: array{negated: bool, chars: list<string>},
     *     head: array{negated: bool, chars: list<string>},
     *     tail: array{negated: bool, chars: list<string>},
     *     first: array<string, mixed>|null,
     *     last: array<string, mixed>|null,
     * }
     */
    protected static function sequenceOutline(string $pattern, int $start, int $end): array
    {
        $alternation = false;
        $mandatory = false;
        $first = null;
        $last = null;
        $offset = $start;

        while ($offset < $end) {
            $character = $pattern[$offset];

            if ($character === '|') {
                $alternation = true;
                $offset++;

                continue;
            }

            $element = $character === '^' || $character === '$'
                ? null
                : static::elementAt($pattern, $offset);

            if ($element === null) {
                $offset++;

                continue;
            }

            $first ??= $element;
            $last = $element;
            $mandatory = $mandatory || $element['mandatory'];
            $offset = max($element['end'], $offset + 1);
        }

        $chars = static::charactersIn($pattern, $start, $end);
        $pinnable = ! $alternation;

        return [
            'alternation' => $alternation,
            'mandatory' => $mandatory && $pinnable,
            'chars' => $chars,
            // The first element's own head and not merely its characters, so that a
            // group nested inside another one keeps whatever its body pinned.
            'head' => $pinnable && $first !== null && $first['mandatory'] ? $first['head'] : $chars,
            'tail' => $pinnable && $last !== null && $last['mandatory'] ? $last['tail'] : $chars,
            'first' => $first,
            'last' => $last,
        ];
    }

    /**
     * Every character any atom between two offsets can match, as one set.
     *
     * A flat read and not a structural one: brackets, branches, anchors and
     * quantifiers match no character of their own, so what is left is the atoms,
     * wherever they are nested.
     *
     * @return array{negated: bool, chars: list<string>}
     */
    protected static function charactersIn(string $pattern, int $start, int $end): array
    {
        $characters = static::noCharacter();
        $offset = $start;

        while ($offset < $end) {
            $character = $pattern[$offset];

            if ($character === '(') {
                $offset = static::groupBodyStart($pattern, $offset);

                continue;
            }

            $atom = static::atomAt($pattern, $offset);

            if ($atom === null) {
                $quantifier = static::quantifierAt($pattern, $offset);
                $offset += $quantifier === null ? 1 : $quantifier['length'];

                continue;
            }

            $characters = static::unionOfSets($characters, $atom['chars']);
            $offset += $atom['length'];
        }

        return $characters;
    }

    /**
     * The one consuming atom at an offset, or null when what sits there is not
     * one: a bracket, a branch, an anchor, or a quantifier with nothing to repeat.
     *
     * `single` answers "does this always consume exactly one character", which is
     * what lets an atom be the separator that pins a repetition boundary. A word
     * boundary consumes none and a backreference consumes a captured run, so
     * neither says yes.
     *
     * @return array{length: int, chars: array{negated: bool, chars: list<string>}, single: bool}|null
     */
    protected static function atomAt(string $pattern, int $offset): ?array
    {
        if ($offset >= strlen($pattern)) {
            return null;
        }

        $character = $pattern[$offset];

        if (in_array($character, ['(', ')', '|', '^', '$', '*', '+', '?'], true)) {
            return null;
        }

        if ($character === '{' && static::quantifierAt($pattern, $offset) !== null) {
            return null;
        }

        if ($character === '\\') {
            return static::escapeAt($pattern, $offset, false);
        }

        if ($character === '[') {
            $end = static::endOfClass($pattern, $offset);

            return [
                'length' => $end - $offset,
                'chars' => static::charactersOfClass($pattern, $offset, $end),
                'single' => true,
            ];
        }

        if ($character === '.') {
            // The edge compiles with no flags at all, so `s` is off and a dot is
            // every character except the four line terminators.
            return [
                'length' => 1,
                'chars' => static::setOf("\n\r\u{2028}\u{2029}", true),
                'single' => true,
            ];
        }

        $width = static::characterWidth($character);

        return [
            'length' => $width,
            'chars' => static::setOf(substr($pattern, $offset, $width)),
            'single' => true,
        ];
    }

    /**
     * The escape sequence at an offset, read the way the edge's flag-less
     * `new RegExp` reads it.
     *
     * Which is not the way PCRE reads it, and the difference is deliberate: with no
     * `u` flag, V8 reads any escape it does not recognise as the character itself,
     * so `\.` is a dot and `\p` is a `p`. Inside a class `\b` is a backspace and
     * outside one it is a word boundary, which is the other place the two contexts
     * disagree.
     *
     * @param  bool  $inClass  Whether the sequence sits inside `[...]`.
     * @return array{length: int, chars: array{negated: bool, chars: list<string>}, single: bool}|null
     */
    protected static function escapeAt(string $pattern, int $offset, bool $inClass): ?array
    {
        $length = strlen($pattern);

        if ($offset + 1 >= $length) {
            // A trailing backslash: the trial compile is what refuses it.
            return null;
        }

        $character = $pattern[$offset + 1];

        if ($character === 'B' || ($character === 'b' && ! $inClass)) {
            return ['length' => 2, 'chars' => static::noCharacter(), 'single' => false];
        }

        if (! $inClass && $character !== '0' && ctype_digit($character)) {
            $width = 1;

            while ($offset + 1 + $width < $length && ctype_digit($pattern[$offset + 1 + $width])) {
                $width++;
            }

            // A backreference matches whatever a group captured, which is neither
            // one character nor a set this scan can name.
            return ['length' => 1 + $width, 'chars' => static::anyCharacter(), 'single' => false];
        }

        $shorthand = match ($character) {
            'd' => static::setOf(self::DIGIT_CHARACTERS),
            'D' => static::setOf(self::DIGIT_CHARACTERS, true),
            'w' => static::setOf(self::WORD_CHARACTERS),
            'W' => static::setOf(self::WORD_CHARACTERS, true),
            's' => static::setOf(self::SPACE_CHARACTERS),
            'S' => static::setOf(self::SPACE_CHARACTERS, true),
            'n' => static::setOf("\n"),
            'r' => static::setOf("\r"),
            't' => static::setOf("\t"),
            'f' => static::setOf("\f"),
            'v' => static::setOf("\v"),
            '0' => static::setOf("\0"),
            'b' => static::setOf("\x08"),
            default => null,
        };

        if ($shorthand !== null) {
            return ['length' => 2, 'chars' => $shorthand, 'single' => true];
        }

        $numeric = static::numericEscapeAt($pattern, $offset);

        if ($numeric !== null) {
            return $numeric;
        }

        $width = static::characterWidth($character);

        return [
            'length' => 1 + $width,
            'chars' => static::setOf(substr($pattern, $offset + 1, $width)),
            'single' => true,
        ];
    }

    /**
     * `\xHH`, `\uHHHH` or `\cA`, or null when the escape is neither of the three or
     * is malformed, which V8 reads as the letter itself and so does the caller.
     *
     * @return array{length: int, chars: array{negated: bool, chars: list<string>}, single: bool}|null
     */
    protected static function numericEscapeAt(string $pattern, int $offset): ?array
    {
        $character = $pattern[$offset + 1];
        $digits = match ($character) {
            'x' => 2,
            'u' => 4,
            default => 0,
        };

        if ($digits > 0) {
            $hex = substr($pattern, $offset + 2, $digits);

            if (strlen($hex) !== $digits || ! ctype_xdigit($hex)) {
                return null;
            }

            $encoded = mb_chr((int) hexdec($hex), 'UTF-8');

            return $encoded === false
                ? null
                : ['length' => 2 + $digits, 'chars' => static::setOf($encoded), 'single' => true];
        }

        if ($character !== 'c' || $offset + 2 >= strlen($pattern) || ! ctype_alpha($pattern[$offset + 2])) {
            return null;
        }

        return [
            'length' => 3,
            'chars' => static::setOf(chr(ord(strtoupper($pattern[$offset + 2])) % 32)),
            'single' => true,
        ];
    }

    /**
     * The characters a class matches, over-approximated.
     *
     * Over-approximated and never under: a member this scan cannot name widens the
     * whole class to every character, which can make two sets look like they
     * overlap when they do not, and overlap is the direction that refuses.
     *
     * @return array{negated: bool, chars: list<string>}
     */
    protected static function charactersOfClass(string $pattern, int $start, int $end): array
    {
        $offset = $start + 1;
        $negated = $offset < $end && $pattern[$offset] === '^';
        $offset += $negated ? 1 : 0;
        $limit = $end > $start && $pattern[$end - 1] === ']' ? $end - 1 : $end;
        $characters = [];

        while ($offset < $limit) {
            $member = static::classMemberAt($pattern, $offset);

            if ($member === null) {
                return static::anyCharacter();
            }

            $offset += $member['length'];
            $range = static::classRangeAt($pattern, $offset, $limit, $member);

            if ($range !== null) {
                if ($range['chars'] === null) {
                    return static::anyCharacter();
                }

                $characters = array_merge($characters, $range['chars']);
                $offset += $range['length'];

                continue;
            }

            $characters = array_merge($characters, $member['chars']['chars']);
        }

        return [
            'negated' => $negated,
            'chars' => array_values(array_unique($characters)),
        ];
    }

    /**
     * One member of a class, or null when it is one this scan cannot name.
     *
     * A member whose own set is a complement (`[\S]`, `[\D]`) is such a case: the
     * class would have to hold the complement of a set beside plain characters,
     * which this two-shape model cannot express, so the caller widens instead.
     *
     * Every unescaped member is one literal character, which is the whole
     * difference between inside a class and outside one: `[a-z0-9.-]` holds a full
     * stop and not every character, and reading it the other way would refuse the
     * ordinary spelling of a URL path.
     *
     * @return array{length: int, chars: array{negated: bool, chars: list<string>}, single: bool}|null
     */
    protected static function classMemberAt(string $pattern, int $offset): ?array
    {
        if ($pattern[$offset] === '\\') {
            $member = static::escapeAt($pattern, $offset, true);

            return $member === null || ! $member['single'] || $member['chars']['negated'] ? null : $member;
        }

        $width = static::characterWidth($pattern[$offset]);

        return [
            'length' => $width,
            'chars' => static::setOf(substr($pattern, $offset, $width)),
            'single' => true,
        ];
    }

    /**
     * The range starting at the `-` at `$offset`, expanded, or null when there is
     * no range there.
     *
     * `chars` is null for a range this scan will not expand: an inverted one, which
     * does not compile anyway, and one wide enough that listing it says nothing a
     * complement would not say better.
     *
     * @param  array{length: int, chars: array{negated: bool, chars: list<string>}, single: bool}  $lower
     * @return array{length: int, chars: list<string>|null}|null
     */
    protected static function classRangeAt(string $pattern, int $offset, int $limit, array $lower): ?array
    {
        // In `[\d-z]` the dash is a member of its own, because a shorthand is not
        // one character, and V8 reads it that way too.
        if (count($lower['chars']['chars']) !== 1 || $offset >= $limit || $pattern[$offset] !== '-') {
            return null;
        }

        $upper = $offset + 1 < $limit ? static::classMemberAt($pattern, $offset + 1) : null;

        if ($upper === null || count($upper['chars']['chars']) !== 1) {
            return null;
        }

        return [
            'length' => 1 + $upper['length'],
            'chars' => static::charactersBetween($lower['chars']['chars'][0], $upper['chars']['chars'][0]),
        ];
    }

    /**
     * Every character from one to another inclusive, or null when the span is
     * inverted or wider than {@see self::WIDEST_EXPANDED_RANGE}.
     *
     * @return list<string>|null
     */
    protected static function charactersBetween(string $from, string $to): ?array
    {
        $lower = mb_ord($from, 'UTF-8');
        $upper = mb_ord($to, 'UTF-8');

        if ($lower === false || $upper === false || $upper < $lower
            || $upper - $lower > self::WIDEST_EXPANDED_RANGE) {
            return null;
        }

        $characters = [];

        for ($point = $lower; $point <= $upper; $point++) {
            $character = mb_chr($point, 'UTF-8');

            if ($character !== false) {
                $characters[] = $character;
            }
        }

        return $characters;
    }

    /** How many bytes the UTF-8 character starting with this byte occupies. */
    protected static function characterWidth(string $lead): int
    {
        $byte = ord($lead);

        return match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
    }

    /**
     * A set of characters, or the complement of one.
     *
     * Two shapes and not one, because the complement of a listed set is exactly
     * what `\W`, `\S` and `[^...]` are, and reading those as "some unknown
     * characters" would refuse `\W*\w*`, which V8 measures flat: the two cannot
     * overlap by construction.
     *
     * @param  string  $characters  Read as a list of UTF-8 characters.
     * @return array{negated: bool, chars: list<string>}
     */
    protected static function setOf(string $characters, bool $negated = false): array
    {
        return [
            'negated' => $negated,
            'chars' => array_values(array_unique(mb_str_split($characters))),
        ];
    }

    /**
     * Every character, which is what an unknown widens to.
     *
     * @return array{negated: bool, chars: list<string>}
     */
    protected static function anyCharacter(): array
    {
        return ['negated' => true, 'chars' => []];
    }

    /**
     * No character, which is what an assertion consumes.
     *
     * @return array{negated: bool, chars: list<string>}
     */
    protected static function noCharacter(): array
    {
        return ['negated' => false, 'chars' => []];
    }

    /**
     * Whether two sets share a character.
     *
     * @param  array{negated: bool, chars: list<string>}  $left
     * @param  array{negated: bool, chars: list<string>}  $right
     */
    protected static function setsIntersect(array $left, array $right): bool
    {
        // Two complements always share one: a 200-character pattern cannot name
        // every code point, so what neither of them excludes is never empty.
        if ($left['negated'] && $right['negated']) {
            return true;
        }

        if ($left['negated']) {
            return static::setsIntersect($right, $left);
        }

        if ($right['negated']) {
            foreach ($left['chars'] as $character) {
                if (! in_array($character, $right['chars'], true)) {
                    return true;
                }
            }

            return false;
        }

        return array_intersect($left['chars'], $right['chars']) !== [];
    }

    /**
     * The two sets as one.
     *
     * @param  array{negated: bool, chars: list<string>}  $left
     * @param  array{negated: bool, chars: list<string>}  $right
     * @return array{negated: bool, chars: list<string>}
     */
    protected static function unionOfSets(array $left, array $right): array
    {
        if ($left['negated'] && $right['negated']) {
            // Neither excludes what only one of them excludes.
            return [
                'negated' => true,
                'chars' => array_values(array_intersect($left['chars'], $right['chars'])),
            ];
        }

        if ($left['negated']) {
            return static::unionOfSets($right, $left);
        }

        if ($right['negated']) {
            return [
                'negated' => true,
                'chars' => array_values(array_diff($right['chars'], $left['chars'])),
            ];
        }

        return [
            'negated' => false,
            'chars' => array_values(array_unique(array_merge($left['chars'], $right['chars']))),
        ];
    }

    /**
     * PCRE's own reason the pattern does not compile, or null when it does.
     *
     * `preg_match()` reports a compile failure as an E_WARNING plus a `false`
     * return and never as a throw, so the handler below is the only way to reach
     * the reason PCRE gives, and the reason is the whole value of this check for
     * an operator: "invalid" sends them looking, "missing closing parenthesis at
     * offset 9" sends them to the character. The handler is installed for the
     * single call and restored in `finally`, so nothing else in the request loses
     * its own error handling.
     */
    protected static function compileError(string $pattern): ?string
    {
        $delimiter = static::trialDelimiter($pattern);

        if ($delimiter === null) {
            return 'the pattern cannot be trial-compiled, because it contains every delimiter this check can use';
        }

        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            // An empty subject: this compiles the pattern, it does not run it
            // against anything worth backtracking over.
            $compiled = preg_match($delimiter.$pattern.$delimiter, '');
        } finally {
            restore_error_handler();
        }

        if ($compiled !== false) {
            return null;
        }

        return $warning === null
            ? 'the pattern is not a valid regular expression'
            : trim(str_replace('preg_match(): ', '', $warning), " \t.");
    }

    /** The first {@see self::TRIAL_DELIMITERS} the pattern does not contain. */
    protected static function trialDelimiter(string $pattern): ?string
    {
        foreach (self::TRIAL_DELIMITERS as $delimiter) {
            if (! str_contains($pattern, $delimiter)) {
                return $delimiter;
            }
        }

        return null;
    }

    /**
     * The opening of every message, naming the rule an operator has to edit.
     *
     * The index is the only identity a rule has, in the editor and in the stored
     * outcome alike, so it is in every sentence this class produces. The field
     * comes first because that is what Laravel substitutes and what a panel shows
     * the message under; the clause after the colon is a sentence about the rule,
     * so each continuation below reads as one.
     */
    protected static function at(int $index): string
    {
        return "The :attribute is invalid: rule at index {$index} ";
    }

    /**
     * Render an operator-supplied value for a message, bounded.
     *
     * Bounded because the value can be a whole page fragment, and an error
     * message that carries one is an error message nobody reads.
     */
    protected static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return '"'.(mb_strlen($value) > 60 ? mb_substr($value, 0, 60).'...' : $value).'"';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return 'a JSON structure';
        }

        return $value === null ? 'null' : (string) $value;
    }

    /**
     * @param  list<string>  $values
     */
    protected static function listOf(array $values): string
    {
        return implode(', ', $values);
    }
}
