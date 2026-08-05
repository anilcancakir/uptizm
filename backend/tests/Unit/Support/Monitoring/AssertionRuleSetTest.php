<?php

namespace Tests\Unit\Support\Monitoring;

use App\Support\Monitoring\AssertionRuleSet;
use PHPUnit\Framework\TestCase;

/**
 * The save-time screen for `monitors.assertion_rules`.
 *
 * WHAT THIS FILE IS ACTUALLY PINNING
 *
 * Two asymmetric errors, and every case below sits on one side or the other. A
 * rule this screen wrongly REFUSES is a rule an operator cannot save at all; a
 * rule it wrongly ALLOWS is skipped at the edge and recorded as skipped (D2). So
 * the accepted-cases tests are as load-bearing as the refused-cases tests, and
 * each of the two catastrophic families the screen catches carries its own pair:
 * every shape it claims to catch is listed, and so is every ordinary pattern it
 * must not touch, including the one documented blind spot and the one measured
 * over-refusal.
 *
 * EVERY PATTERN BELOW WAS TIMED IN V8, WHICH IS THE ENGINE THAT RUNS IT
 *
 * A pattern is in the refused list because it was measured super-linear in
 * `node`, and in the accepted list because it was measured flat, both over a
 * growing adversarial subject. The numbers are in the class docblock of
 * {@see AssertionRuleSet}; the two lists are not derived from what the scan
 * happens to do.
 */
class AssertionRuleSetTest extends TestCase
{
    /** A monitor asserting nothing is a valid monitor, not an empty rule set. */
    public function test_no_rules_at_all_is_accepted(): void
    {
        $this->assertSame([], AssertionRuleSet::problems(null));
        $this->assertSame([], AssertionRuleSet::problems([]));
    }

    /**
     * Every legal target/operator pairing the edge implements, in both value
     * spellings where both are legal.
     */
    public function test_the_whole_vocabulary_is_accepted(): void
    {
        $rules = [
            ['target' => 'status_code', 'operator' => 'equals', 'value' => 200],
            // The string spelling of a number is NOT a mistake: the edge follows
            // the kind of the value, and `"200"` compares the status code as
            // text, which is the same intent expressed differently.
            ['target' => 'status_code', 'operator' => 'equals', 'value' => '200'],
            ['target' => 'status_code', 'operator' => 'not_equals', 'value' => 500],
            ['target' => 'status_code', 'operator' => 'greater_than', 'value' => 199],
            ['target' => 'status_code', 'operator' => 'less_than', 'value' => 300],
            ['target' => 'status_code', 'operator' => 'matches_regex', 'value' => '^(200|302)$'],
            ['target' => 'status_code', 'operator' => 'exists'],
            ['target' => 'response_time_ms', 'operator' => 'less_than', 'value' => '1500'],
            ['target' => 'response_time_ms', 'operator' => 'not_exists'],
            ['target' => 'body', 'operator' => 'contains', 'value' => 'ok'],
            ['target' => 'body', 'operator' => 'not_contains', 'value' => 'exception'],
            ['target' => 'body', 'operator' => 'matches_regex', 'value' => '"status"\s*:\s*"up"'],
            ['target' => 'body', 'operator' => 'not_matches_regex', 'value' => 'stack trace'],
            ['target' => 'body', 'operator' => 'exists'],
            ['target' => 'header', 'operator' => 'equals', 'value' => 'application/json', 'name' => 'Content-Type'],
            ['target' => 'header', 'operator' => 'not_exists', 'name' => 'X-Debug'],
            ['target' => 'header', 'operator' => 'exists', 'name' => 'strict-transport-security'],
        ];

        $this->assertSame([], AssertionRuleSet::problems($rules));
    }

    /** A bare scalar is valid JSON and would reach the edge as a non-array. */
    public function test_a_decoded_scalar_is_refused(): void
    {
        $problems = AssertionRuleSet::problems(5);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('JSON array', $problems[0]);
    }

    /** A JSON object at the top level is an array the edge never iterates. */
    public function test_a_json_object_at_the_top_level_is_refused(): void
    {
        $problems = AssertionRuleSet::problems(['target' => 'body', 'operator' => 'exists']);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('JSON array', $problems[0]);
    }

    public function test_an_element_that_is_not_an_object_is_refused_by_index(): void
    {
        $problems = AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'exists'],
            'contains ok',
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('index 1', $problems[0]);
        $this->assertStringContainsString('JSON object', $problems[0]);
    }

    public function test_an_unknown_target_is_refused_naming_the_index_and_the_token(): void
    {
        // `field` was the key the panel's own test fixtures used while no shape
        // was defined anywhere, so it is the drift most likely to be pasted in.
        $problems = AssertionRuleSet::problems([
            ['field' => 'body', 'operator' => 'contains', 'value' => 'ok'],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('index 0', $problems[0]);
        $this->assertStringContainsString('target', $problems[0]);
        $this->assertStringContainsString('status_code', $problems[0]);
    }

    public function test_a_target_outside_the_vocabulary_is_refused(): void
    {
        $problems = AssertionRuleSet::problems([
            ['target' => 'json_path', 'operator' => 'equals', 'value' => 'up'],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('index 0', $problems[0]);
        $this->assertStringContainsString('json_path', $problems[0]);
    }

    public function test_an_unknown_operator_is_refused_naming_the_index_and_the_token(): void
    {
        $problems = AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'contains', 'value' => 'ok'],
            ['target' => 'status_code', 'operator' => 'eq', 'value' => 204],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('index 1', $problems[0]);
        $this->assertStringContainsString('operator', $problems[0]);
        $this->assertStringContainsString('eq', $problems[0]);
    }

    public function test_a_header_rule_must_name_a_header(): void
    {
        foreach ([[], ['name' => null], ['name' => '  '], ['name' => 42]] as $index => $missing) {
            $problems = AssertionRuleSet::problems([
                array_merge(['target' => 'header', 'operator' => 'exists'], $missing),
            ]);

            $this->assertCount(1, $problems, "Shape {$index} was accepted.");
            $this->assertStringContainsString('index 0', $problems[0]);
            $this->assertStringContainsString('name', $problems[0]);
        }
    }

    /** A `name` on a non-header rule is inert at the edge, so it is not a fault. */
    public function test_a_name_on_a_non_header_rule_is_tolerated(): void
    {
        $this->assertSame([], AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'contains', 'value' => 'ok', 'name' => 'leftover'],
        ]));
    }

    public function test_a_comparison_without_a_value_is_refused(): void
    {
        $problems = AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'contains'],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('index 0', $problems[0]);
        $this->assertStringContainsString('value', $problems[0]);
    }

    /** JSON `true` and a nested structure both reach the edge as `value_invalid`. */
    public function test_a_value_that_is_neither_text_nor_a_number_is_refused(): void
    {
        foreach ([true, ['ok'], null] as $value) {
            $problems = AssertionRuleSet::problems([
                ['target' => 'body', 'operator' => 'contains', 'value' => $value],
            ]);

            $this->assertCount(1, $problems);
            $this->assertStringContainsString('value', $problems[0]);
        }
    }

    public function test_a_numeric_target_refuses_a_value_that_can_never_match(): void
    {
        $problems = AssertionRuleSet::problems([
            ['target' => 'status_code', 'operator' => 'equals', 'value' => 'OK'],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('index 0', $problems[0]);
        $this->assertStringContainsString('number', $problems[0]);
    }

    public function test_an_ordering_operator_refuses_a_bound_that_is_not_a_number(): void
    {
        $problems = AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'greater_than', 'value' => 'soon'],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('number', $problems[0]);
    }

    /** A text comparison over a numeric target is the blessed `200|302` case. */
    public function test_a_text_operator_over_a_numeric_target_is_accepted(): void
    {
        $this->assertSame([], AssertionRuleSet::problems([
            ['target' => 'status_code', 'operator' => 'contains', 'value' => '20'],
            ['target' => 'response_time_ms', 'operator' => 'matches_regex', 'value' => '^\d{1,3}$'],
        ]));
    }

    /**
     * The two caps are different questions with different numbers, and the gap
     * between them is deliberate.
     *
     * 200 characters bounds what a pattern may COST at the edge, so it is as tight
     * as a real pattern tolerates. {@see AssertionRuleSet::RULE_MAX_BYTES} bounds
     * what a rule WEIGHS on the processing queue, so it is as loose as a real body
     * needle needs: 500 characters of `contains` cannot backtrack and is nowhere
     * near the size bound, so it stays saveable.
     */
    public function test_a_long_text_value_is_accepted_when_it_is_not_a_pattern(): void
    {
        $this->assertSame([], AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'contains', 'value' => str_repeat('a', 500)],
        ]));
    }

    /**
     * WHY A SIZE CAP EXISTS AT ALL, since the ReDoS screen does not need one.
     *
     * Every outcome the edge records echoes the rule VERBATIM, and the report rides
     * to the persisting job through the Redis `processing` queue, whose keys carry
     * no TTL and whose `volatile-lru` eviction victims are the check-persistence
     * locks. A single `body contains` rule holding a 500 KB needle would therefore
     * push half a megabyte into that store on every check from every region, which
     * is the exact traffic `CheckResult::toArray()` excludes `content` to avoid.
     * The observed value is cut to 256 characters at the edge; the rule is not cut
     * anywhere, so this screen is where it is bounded.
     */
    public function test_a_rule_far_larger_than_any_real_needle_is_refused(): void
    {
        $problems = AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'contains', 'value' => str_repeat('a', 500_000)],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('index 0', $problems[0]);
        $this->assertStringContainsString((string) AssertionRuleSet::RULE_MAX_BYTES, $problems[0]);
    }

    /**
     * The boundary, derived from the JSON the rule is stored and echoed as rather
     * than from the screen's own arithmetic, so a change of shape cannot make this
     * pass by moving both sides at once.
     */
    public function test_a_rule_at_the_size_cap_is_accepted_and_one_byte_over_is_refused(): void
    {
        $overhead = strlen((string) json_encode([
            'target' => 'body',
            'operator' => 'contains',
            'value' => '',
        ]));
        $room = AssertionRuleSet::RULE_MAX_BYTES - $overhead;

        $this->assertSame([], AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'contains', 'value' => str_repeat('a', $room)],
        ]));

        $problems = AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'contains', 'value' => str_repeat('a', $room + 1)],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('index 0', $problems[0]);
        $this->assertStringContainsString((string) AssertionRuleSet::RULE_MAX_BYTES, $problems[0]);
    }

    /**
     * The cap is measured over the WHOLE rule, and that is not incidental.
     *
     * `value` is the field an operator fills, but it is not the only one the edge
     * echoes: a `name` on a non-header rule is inert rather than wrong (see the
     * test above that pins that tolerance), and an unknown key is tolerated for the
     * same reason. Both ride into `assertion_results` verbatim, so a cap on `value`
     * alone would leave the same half-megabyte reachable through a field nobody
     * validates.
     */
    public function test_bulk_in_a_field_the_screen_otherwise_tolerates_is_refused(): void
    {
        $bulk = str_repeat('a', 500_000);
        $shapes = [
            ['target' => 'body', 'operator' => 'exists', 'name' => $bulk],
            ['target' => 'body', 'operator' => 'contains', 'value' => 'ok', 'note' => $bulk],
        ];

        foreach ($shapes as $index => $rule) {
            $problems = AssertionRuleSet::problems([$rule]);

            $this->assertCount(1, $problems, "Shape {$index} was accepted.");
            $this->assertStringContainsString((string) AssertionRuleSet::RULE_MAX_BYTES, $problems[0]);
        }
    }

    /**
     * A cap on one rule bounds nothing without a cap on how many there are: the
     * report is the sum, and 500 well-formed rules are 500 echoed outcomes on every
     * check from every region.
     *
     * The number is the most permissive of the surveyed products (Datadog caps
     * assertions at 20, UptimeRobot at 5), so it refuses nothing anyone else allows.
     */
    public function test_more_rules_than_one_monitor_may_carry_are_refused(): void
    {
        $rule = ['target' => 'status_code', 'operator' => 'equals', 'value' => 200];

        $this->assertSame(
            [],
            AssertionRuleSet::problems(array_fill(0, AssertionRuleSet::RULES_MAX_COUNT, $rule)),
            'The limit itself must be saveable.',
        );

        $problems = AssertionRuleSet::problems(
            array_fill(0, AssertionRuleSet::RULES_MAX_COUNT + 1, $rule),
        );

        // One message and not one per rule past the limit: the count is a single
        // fault, and the index named is the first rule that does not fit.
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('index '.AssertionRuleSet::RULES_MAX_COUNT, $problems[0]);
        $this->assertStringContainsString((string) AssertionRuleSet::RULES_MAX_COUNT, $problems[0]);
    }

    public function test_a_pattern_at_the_length_cap_is_accepted_and_one_character_over_is_refused(): void
    {
        $this->assertSame([], AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'matches_regex', 'value' => str_repeat('a', 200)],
        ]));

        $problems = AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'not_matches_regex', 'value' => str_repeat('a', 201)],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('index 0', $problems[0]);
        $this->assertStringContainsString('200', $problems[0]);
    }

    /**
     * Every shape the first scan CLAIMS to catch: a repeating quantifier applied
     * to a group whose body itself contains an unbounded quantifier, and whose
     * repetitions have no forced boundary between them.
     *
     * `(a+)+b` is exponential in V8 (3.2ms at n=16, 2.9s at n=28, 46.6s at n=32).
     * `(\w+\s+)+error` is here because its body ends in a repeat rather than in a
     * separator, which is the only thing that pins a boundary, and NOT because it
     * was measured catastrophic: anchored it is flat under four different
     * adversarial subjects, and its 229ms unanchored is the start-position sweep
     * that `\w+error` pays identically. It is a measured over-refusal, recorded as
     * one in {@see AssertionRuleSet}, kept because the exemption that would release
     * it would release the whole family.
     */
    public function test_each_nested_quantifier_shape_is_refused(): void
    {
        $patterns = [
            '(a+)+',
            '(a*)*',
            '(x+)*',
            '(x*)+',
            '([a-z]+)*',
            '(x+x+)+y',
            '(?:\w+)*',
            '((a)+)+',
            '(a+?)+',
            '(\s*)*',
            '(a{2,})+',
            '^(.*)+$',
            '(a+){3}',
            '(\w+\s+)+error',
        ];

        foreach ($patterns as $pattern) {
            $problems = AssertionRuleSet::problems([
                ['target' => 'body', 'operator' => 'matches_regex', 'value' => $pattern],
            ]);

            $this->assertCount(1, $problems, "Pattern [{$pattern}] was accepted.");
            $this->assertStringContainsString('index 0', $problems[0]);
            $this->assertStringContainsString('nested quantifier', $problems[0]);
            $this->assertStringContainsString('offset', $problems[0]);
        }
    }

    /**
     * Ordinary patterns with a quantified group, which the heuristic must NOT
     * touch. A guard that refuses these is a guard operators route around.
     */
    public function test_ordinary_patterns_with_a_quantified_group_are_accepted(): void
    {
        $patterns = [
            '(\d{3})+',
            '(https?://)+',
            '^(GET|POST) /health$',
            '(ab)+',
            '(a|b)+',
            // `+` inside a character class is a member, not a quantifier.
            '([+])+',
            // Escaped parentheses open no group at all.
            '\(a+\)+',
            '[0-9a-f]{8}-[0-9a-f]{4}',
            '"status"\s*:\s*"(up|ok)"',
            '(?=.*error)',
            // Two unbounded repeats with a MANDATORY element between them: the
            // split is pinned, and V8 measures it flat where the same pattern
            // with `\.?` is quadratic.
            '^\d*\.\d*$',
            // Two unbounded repeats over disjoint classes, which cannot absorb
            // each other's characters: 0.1ms at n=8,000 against `^[a-z]+[0-9]*$`.
            '^[a-z]+[0-9]*$',
            // The same, through the complement of a class rather than a listing
            // of one: 0.0ms at n=8,000 against `^\W*\w*x$`.
            '\W*\w*',
            // Inside a class a full stop is a full stop. Reading it as the dot
            // metacharacter would widen the class to every character and refuse
            // both of these, and the second is how a URL path is written.
            '^[a-z.]+[0-9]*$',
            '^https://[a-z0-9.-]+/[a-z0-9/_-]*$',
        ];

        foreach ($patterns as $pattern) {
            $this->assertSame(
                [],
                AssertionRuleSet::problems([
                    ['target' => 'body', 'operator' => 'matches_regex', 'value' => $pattern],
                ]),
                "Pattern [{$pattern}] was refused.",
            );
        }
    }

    /**
     * The second family: two unbounded quantifiers reachable back to back over
     * atoms that can match the same character. It needs no nesting at all, and it
     * is the one the screen missed.
     *
     * Measured in V8, adversarial subject, growing n:
     *
     * - `.*.*=.*` (the core of the regex that took Cloudflare's edge down in
     *   2019): 63.8ms at n=500, 503ms at n=1,000, 4.0s at n=2,000, 32.0s at
     *   n=4,000. Cubic, and the edge feeds it a body up to a megabyte.
     * - `str_repeat('a*', 90).'b'`, 181 characters, inside the cap: 82.8s at
     *   n=125. The degree grows with each additional repeat.
     * - `(.*)(.*)=.*` and `(?:.*)(?:.*)=.*`: 4.0s at n=2,000 each, to the
     *   millisecond the same as the pattern without the parentheses. A pair of
     *   brackets is not a difference, so it must not be a way through.
     * - `^a*b?a*c$`: 5.6ms at n=2,000, quadratic. An OPTIONAL element between the
     *   two repeats pins nothing.
     * - `^\d*\.?\d*$`: 90.1ms at n=8,000, quadratic. Same reason, and it is the
     *   shape an operator reaches for when they mean "a number, maybe decimal".
     * - `^[a-z]+[a-z0-9]*$`: 94.6ms at n=8,000, quadratic. Ordinary-looking and
     *   genuinely catastrophic, because the two classes overlap.
     * - `^é*é*z$`: 22.6ms at n=4,000, quadratic. The scan reads UTF-8, so a
     *   multi-byte literal is one atom and not two.
     */
    public function test_two_unbounded_quantifiers_in_sequence_are_refused(): void
    {
        $patterns = [
            '.*.*=.*',
            str_repeat('a*', 90).'b',
            '(.*)(.*)=.*',
            '(?:.*)(?:.*)=.*',
            '^(a*)(a*)(a*)b$',
            '^a*b?a*c$',
            '^\d*\.?\d*$',
            '^[a-z]+[a-z0-9]*$',
            '^\d+[0-9a-f]*z$',
            '^é*é*z$',
        ];

        foreach ($patterns as $pattern) {
            $problems = AssertionRuleSet::problems([
                ['target' => 'body', 'operator' => 'matches_regex', 'value' => $pattern],
            ]);

            $this->assertCount(1, $problems, "Pattern [{$pattern}] was accepted.");
            $this->assertStringContainsString('index 0', $problems[0]);
            $this->assertStringContainsString('unbounded quantifiers in sequence', $problems[0]);
            $this->assertStringContainsString('offset', $problems[0]);
        }
    }

    /**
     * The patterns the first scan refused before it asked whether the repetitions
     * have a forced boundary. All five are flat in V8 at every size measured
     * (0.0ms to 0.2ms from n=1,000 to n=16,000), so refusing them was the
     * expensive direction of the asymmetry: the natural
     * `header Location matches_regex` rule for a hostname could not be saved at
     * all.
     *
     * The boundary is forced either by the last element of the body
     * (`(\d+\.)+`, every repetition ends on the one `.`) or by the first
     * (`\d+(\.\d+)+`, every repetition begins on it), which is the same argument
     * mirrored.
     */
    public function test_a_repeat_whose_boundary_is_pinned_by_a_separator_is_accepted(): void
    {
        $patterns = [
            '^(\d+\.)+\d+$',
            '^([a-z0-9-]+\.)+example\.com$',
            '^\d+(\.\d+)+$',
            '^([a-z]+\.)+[a-z]{2,}$',
            '((a+)\.)+',
        ];

        foreach ($patterns as $pattern) {
            $this->assertSame(
                [],
                AssertionRuleSet::problems([
                    ['target' => 'header', 'operator' => 'matches_regex', 'value' => $pattern, 'name' => 'Location'],
                ]),
                "Pattern [{$pattern}] was refused.",
            );
        }
    }

    /**
     * What sits BETWEEN two repeats decides whether it separates them, and a
     * mandatory element is not enough on its own.
     *
     * This pair came out of a differential pass over 260 generated patterns, timed
     * in V8 against the screen's verdict, and it was a miss: an element both
     * repeats can match stands in for either of them, so the boundary can still
     * slide and the pair is still quadratic (`^[a-z]+.\w+$` is 96.7ms at n=4,000,
     * quadratic, with a dot metacharacter between the two). One that only ONE side
     * can match does pin it, which is why the ordinary spelling of an email address
     * stays saveable: `[a-z0-9.-]+` can match the `\.` that follows it, but
     * `[a-z]{2,}` cannot, and V8 measures it flat at n=4,000.
     */
    public function test_a_separator_pins_only_the_repeats_that_cannot_match_it(): void
    {
        $problems = AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'matches_regex', 'value' => '^[a-z]+.\w+$'],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('unbounded quantifiers in sequence', $problems[0]);

        $this->assertSame([], AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'matches_regex', 'value' => '^[a-z0-9.-]+@[a-z0-9.-]+\.[a-z]{2,}$'],
        ]));
    }

    /**
     * The two scans compose, and the second is what keeps the first one's escape
     * hatch honest.
     *
     * `(\d+\d+\.)+` has the forced boundary the first scan looks for, so that
     * scan lets it through; the body between two boundaries is ambiguous on its
     * own, and V8 measures it quadratic (91.3ms at n=8,000 against
     * `^(\d+\d+\.)+$`). The second scan is what refuses it, which is why the
     * escape hatch is safe to have.
     */
    public function test_an_ambiguous_body_inside_a_pinned_repeat_is_still_refused(): void
    {
        $problems = AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'matches_regex', 'value' => '(\d+\d+\.)+'],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('unbounded quantifiers in sequence', $problems[0]);
    }

    /**
     * The measured cost of the line the second scan draws, pinned so that it is a
     * decision and not a surprise.
     *
     * `(ab)+(ba)+c` shares characters between the two repeats, so the scan
     * refuses it, and V8 measures it flat (0.1ms at n=1,000 through n=8,000)
     * because the concatenation happens to be unambiguous anyway. Deciding that
     * needs language-level reasoning about multi-character groups, which is the
     * NFA analysis D5 declined. Refusing on characters two adjacent repeats share
     * is the line; this is what it costs.
     */
    public function test_two_repeats_sharing_a_character_are_refused_even_when_unambiguous(): void
    {
        $problems = AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'matches_regex', 'value' => '(ab)+(ba)+c'],
        ]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('unbounded quantifiers in sequence', $problems[0]);
    }

    /**
     * The documented blind spot, asserted rather than left to be discovered.
     *
     * `(a|aa)+` backtracks catastrophically through ALTERNATION OVERLAP: measured
     * against `(a|aa)+b` in V8, 0.3ms at n=20, 11.3ms at n=28, 77.8ms at n=32,
     * exponential on a smaller base than `(a+)+b`. It carries neither a nested
     * quantifier nor two quantifiers in sequence, so neither scan finds it;
     * deciding it needs the NFA ambiguity analysis that a real ReDoS detector is,
     * and D5 accepted a heuristic instead. The remaining containment for this
     * shape is the length cap, the edge's body ceiling, and the fact that the only
     * writer is the staff panel. This test exists so the gap is a recorded
     * property of the screen and not a surprise.
     */
    public function test_alternation_overlap_is_a_documented_blind_spot(): void
    {
        /*
         * Both shapes are ACCEPTED today, and both are pinned so that closing the
         * gap flips a test rather than passing in silence.
         *
         * The second is the one that says how big the gap is. Sixty
         * single-character branches over the same character is 125 characters,
         * inside every cap, and measured at 64.8 seconds in V8 against a subject
         * of SIXTEEN characters, with n=20 not returning inside a four-minute
         * budget. Sixteen characters is shorter than any body worth asserting on,
         * so the edge's 10 KiB pattern ceiling buys nothing against it.
         *
         * What holds the risk down is that the only author is staff behind the
         * Filament panel. Closing it is affordable rather than open-ended:
         * refusing a repeating quantifier over a group whose top-level branches
         * are not provably disjoint reuses the character-set model already in
         * this class.
         */
        $this->assertSame([], AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'matches_regex', 'value' => '(a|aa)+'],
        ]));

        $sixtyBranches = '^('.implode('|', array_fill(0, 60, 'a')).')+z$';

        $this->assertLessThanOrEqual(AssertionRuleSet::PATTERN_MAX_CHARS, strlen($sixtyBranches));
        $this->assertSame([], AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'matches_regex', 'value' => $sixtyBranches],
        ]));
    }

    public function test_a_bounded_inner_repeat_with_a_range_is_refused(): void
    {
        /*
         * The scan read only UNBOUNDED inner quantifiers, so the whole `{m,n}`
         * family walked through, including the tail of the most-pasted email
         * regex on the internet. Measured in V8: `([a-zA-Z]{2,4})+$` is 103 ms at
         * n=44, 479 ms at 48, 2.21 s at 52 and 10.2 s at 56, about 4.6x per four
         * characters of subject.
         *
         * A RANGE is what does it, not a count. `{3}` has exactly one way to match
         * three characters, so `(\d{3})+` splits a digit run deterministically and
         * stays linear; `{2,4}` lets the engine try three lengths for the same
         * span. Reading `repeating` here instead of variable-length refused
         * `(\d{3})+`, which the accepted-patterns case above still pins.
         */
        foreach ([
            '([a-zA-Z]{2,4})+$',
            '^(\w{1,10})+$',
            '^(\d{2,4})+$',
            '^([a-z]{1,2})*$',
            '^(a{2,4}){2,20}$',
            '^([a-zA-Z0-9_\.\-])+\@(([a-zA-Z\-])+\.)+([a-zA-Z]{2,4})+$',
        ] as $pattern) {
            $problems = AssertionRuleSet::problems([
                ['target' => 'body', 'operator' => 'matches_regex', 'value' => $pattern],
            ]);

            $this->assertCount(1, $problems, "Pattern [{$pattern}] was accepted.");
            $this->assertStringContainsString('index 0', $problems[0]);
        }
    }

    public function test_a_pattern_that_does_not_compile_is_refused(): void
    {
        foreach (['(unclosed', 'a{2,1}', '*bad', '[z-a]'] as $pattern) {
            $problems = AssertionRuleSet::problems([
                ['target' => 'body', 'operator' => 'matches_regex', 'value' => $pattern],
            ]);

            $this->assertCount(1, $problems, "Pattern [{$pattern}] was accepted.");
            $this->assertStringContainsString('index 0', $problems[0]);
            $this->assertStringContainsString('does not compile', $problems[0]);
        }
    }

    /** The refusal must survive a pattern that would abort a naive trial compile. */
    public function test_a_pattern_containing_the_trial_delimiter_still_compiles(): void
    {
        $this->assertSame([], AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'matches_regex', 'value' => 'https?://[a-z]+/health'],
        ]));
    }

    /** One problem per offending rule, each naming its own index. */
    public function test_every_offending_index_is_reported(): void
    {
        $problems = AssertionRuleSet::problems([
            ['target' => 'nope', 'operator' => 'equals', 'value' => 1],
            ['target' => 'body', 'operator' => 'contains', 'value' => 'ok'],
            ['target' => 'body', 'operator' => 'matches_regex', 'value' => '(a+)+'],
        ]);

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('index 0', $problems[0]);
        $this->assertStringContainsString('index 2', $problems[1]);
    }

    /**
     * Every message is a Laravel validation message, so a caller hands it to
     * `$fail()` unchanged and the field's own label is substituted in.
     */
    public function test_every_message_carries_the_attribute_placeholder(): void
    {
        $problems = AssertionRuleSet::problems([
            5,
            ['target' => 'body', 'operator' => 'eq', 'value' => 'ok'],
        ]);

        $this->assertNotEmpty($problems);

        foreach ($problems as $problem) {
            $this->assertStringStartsWith('The :attribute', $problem);
        }
    }
}
