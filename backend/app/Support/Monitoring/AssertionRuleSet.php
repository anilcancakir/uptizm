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
 * WHAT THE REGEX SCREEN DOES AND DOES NOT PROVE (D5)
 *
 * `matches_regex` and `not_matches_regex` execute an operator-supplied, stored
 * pattern on JavaScript's backtracking RegExp at the edge, on a runtime with a
 * CPU budget. There is no RE2 there by decision, so this screen is the actual
 * containment, and it is a heuristic rather than a proof of linear-time matching:
 *
 * - The 200-character cap applies to PATTERNS ONLY. A `body contains` value is
 *   compared with `String.includes`, which cannot backtrack, so capping it would
 *   be a restriction with no reason behind it.
 * - {@see self::nestedQuantifierOffset()} catches ONE family: a repeating
 *   quantifier applied to a group whose body itself contains an unbounded
 *   quantifier (`(x+)+`, `(x*)*`, `([a-z]+)*`, `(x+x+)+y`, `((a)+)+`). It does
 *   NOT catch alternation overlap (`(a|aa)+`), which is equally catastrophic and
 *   needs NFA ambiguity analysis to decide. That gap is asserted in
 *   `AssertionRuleSetTest` so it stays a known property.
 * - The trial compile proves the pattern compiles under PCRE, which is NOT the
 *   engine that will run it. The two diverge in both directions: PCRE accepts
 *   `\A`, `(?i)`, `(?>...)` and possessive quantifiers that V8 rejects (those
 *   reach the edge and become a recorded `regex_invalid` skip, never a false
 *   verdict), and V8 accepts variable-length lookbehind that PCRE rejects (those
 *   are refused here, which is the safe direction: the operator learns at save
 *   time instead of collecting silent skips).
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
         * and the edge treats as an object. It is accepted here and skipped
         * there, which is the allowed direction of the asymmetry above.
         */
        if (! is_array($rules) || ! array_is_list($rules)) {
            return ['The :attribute must be a JSON array of assertion rules.'];
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

        // 2. The vocabulary, mirrored from the edge's union.
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

        // 3. A `header` rule addresses one header by name. All three shapes the
        //    editor can produce (the key omitted, an explicit null, a field left
        //    blank) are the same fault, and the edge skips every one of them.
        if ($target === 'header') {
            $name = $rule['name'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                return static::at($index).'targets a header without naming one; add a "name" field.';
            }
        }

        // 4. Presence operators read no value, so a leftover one beside them is
        //    inert at the edge rather than wrong.
        if (in_array($operator, self::PRESENCE_OPERATORS, true)) {
            return null;
        }

        // 5. The value the comparison needs. An absent key, an explicit null, a
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

        // 6. A comparison that can never answer, or can only ever answer one
        //    way. An ordering over a non-number is unanswerable at the edge and
        //    skipped there; equality against a numeric target with a value that
        //    is not a number is answerable and constant, which is worse, because
        //    it is a rule that looks like it is watching something.
        if (static::comparesNumerically($target, $operator) && static::asNumber($value) === null) {
            return static::at($index)."compares [{$target}] with [{$operator}], so its value must be a number; got "
                .static::describe($value).'.';
        }

        // 7. The regex screen (D5). Last, because the six checks above are
        //    cheap and a pattern is the only value that costs anything to look
        //    at.
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
     * without being scanned, the structural scan runs on a bounded string, and
     * only then is PCRE asked to compile it.
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
                .' edge; rewrite it with a bounded repeat.';
        }

        $error = static::compileError($pattern);

        if ($error !== null) {
            return static::at($index).'has a pattern that does not compile: '.$error.'.';
        }

        return null;
    }

    /**
     * The offset of a repeating quantifier applied to a group whose body already
     * contains an unbounded one, or null when the pattern carries none.
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
     */
    protected static function nestedQuantifierOffset(string $pattern): ?int
    {
        $length = strlen($pattern);
        $repeatsInside = [];
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
                $repeatsInside[] = false;
                $offset++;

                continue;
            }

            if ($character === ')') {
                $body = array_pop($repeatsInside) ?? false;
                $quantifier = static::quantifierAt($pattern, $offset + 1);

                if ($body && $quantifier !== null && $quantifier['repeating']) {
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
                if ($quantifier['unbounded']) {
                    $repeatsInside = array_map(static fn (): bool => true, $repeatsInside);
                }

                $offset += $quantifier['length'];

                continue;
            }

            $offset++;
        }

        return null;
    }

    /**
     * The quantifier at an offset, or null when there is none there.
     *
     * `repeating` answers "can this run its atom more than once", which is what
     * makes an outer quantifier dangerous, so `?` is excluded from it: one
     * optional pass cannot blow up. `unbounded` answers "can this run its atom
     * without a ceiling", which is what makes an INNER quantifier dangerous, so a
     * bounded inner repeat such as `(\d{3})+` is left alone: it is polynomial in
     * a fixed, small factor, not exponential.
     *
     * @return array{length: int, repeating: bool, unbounded: bool}|null
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

        if ($character === '?') {
            $repeating = false;
            $unbounded = false;
        } elseif ($character === '{') {
            $closing = strpos($pattern, '}', $offset);

            // A `{` that does not close, or does not hold a count, is a literal
            // brace and not a quantifier at all; V8 reads it that way too.
            if ($closing === false || preg_match('/^(\d+)(,(\d*))?$/', substr($pattern, $offset + 1, $closing - $offset - 1), $bounds) !== 1) {
                return null;
            }

            $width = $closing - $offset + 1;
            $unbounded = isset($bounds[2]) && $bounds[3] === '';
            $maximum = $unbounded
                ? null
                : (int) (($bounds[3] ?? '') === '' ? $bounds[1] : $bounds[3]);
            $repeating = $unbounded || $maximum >= 2;
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
