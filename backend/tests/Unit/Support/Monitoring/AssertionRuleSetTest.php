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
 * the two regex families carry their own pair: every shape the nested-quantifier
 * heuristic claims to catch is listed, and so is every ordinary pattern it must
 * not touch, including one documented blind spot it does not catch at all.
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
     * The 200-character cap is the ReDoS screen and applies to PATTERNS only. A
     * long body fragment carries no backtracking hazard, and capping it would be
     * a restriction with no reason behind it.
     */
    public function test_a_long_text_value_is_accepted_when_it_is_not_a_pattern(): void
    {
        $this->assertSame([], AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'contains', 'value' => str_repeat('a', 500)],
        ]));
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
     * Every shape the heuristic CLAIMS to catch: a repeating quantifier applied
     * to a group whose body itself contains an unbounded quantifier.
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
     * The documented blind spot, asserted rather than left to be discovered.
     *
     * `(a|aa)+` backtracks catastrophically through ALTERNATION OVERLAP and
     * carries no nested quantifier, so no structural scan finds it; deciding it
     * needs NFA ambiguity analysis, which is what a real ReDoS detector is, and
     * D5 accepted a heuristic instead. The remaining containment for this shape
     * is the length cap, the edge's body ceiling, and the fact that the surface
     * is authenticated team members. This test exists so the gap is a recorded
     * property of the screen and not a surprise.
     */
    public function test_alternation_overlap_is_a_documented_blind_spot(): void
    {
        $this->assertSame([], AssertionRuleSet::problems([
            ['target' => 'body', 'operator' => 'matches_regex', 'value' => '(a|aa)+'],
        ]));
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
