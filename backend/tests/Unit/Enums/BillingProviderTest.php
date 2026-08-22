<?php

namespace Tests\Unit\Enums;

use App\Enums\BillingProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rail half of the neutral billing vocabulary.
 *
 * WHY THIS FILE PINS THE CASE SET ITSELF
 *
 * These wire values are a contract, not an implementation detail: a public
 * client package decodes them and a shipped build cannot be recalled, so
 * renaming `app_store` or dropping `manual` later is a break for every
 * installed copy. The first test therefore asserts the exact value list
 * rather than iterating over whatever `cases()` happens to return, which
 * means adding, renaming or removing a case is a deliberate edit here and
 * never an accident.
 *
 * The two predicates are asserted per case, exhaustively, for the same
 * reason: `grants()` and `isStore()` each carry a `match` with no `default`
 * arm, and the whole point of that shape is that a new case has to be
 * decided rather than defaulting to false. A truth table keyed by wire value
 * makes the test fail on a new case too, so the decision cannot be skipped.
 */
class BillingProviderTest extends TestCase
{
    /**
     * The wire contract, verbatim. Change this list only when the wire itself
     * is deliberately changing.
     */
    public function test_the_case_set_is_exactly_the_wire_contract(): void
    {
        $this->assertSame(
            [
                'none',
                'stripe',
                'app_store',
                'play_store',
                'manual',
            ],
            array_map(fn (BillingProvider $provider): string => $provider->value, BillingProvider::cases()),
        );
    }

    /** Every case decodes from its own wire value, so a round trip is lossless. */
    public function test_every_case_round_trips_through_from_wire(): void
    {
        foreach (BillingProvider::cases() as $provider) {
            $this->assertSame(
                $provider,
                BillingProvider::fromWire($provider->value),
                "{$provider->value} did not round trip",
            );
        }
    }

    /**
     * An unrecognised rail must land on `none`, never on an existing rail: a
     * future provider read as Stripe would let a Stripe event revoke a grant
     * Stripe never made.
     */
    public function test_an_unknown_wire_value_falls_back_to_none(): void
    {
        $this->assertSame(BillingProvider::None, BillingProvider::fromWire('nonsense'));
        $this->assertSame(BillingProvider::None, BillingProvider::fromWire('rc_billing'));
        $this->assertSame(BillingProvider::None, BillingProvider::fromWire('Stripe'));
    }

    /** A column that predates any rail is null, and null is not an error. */
    public function test_a_null_or_empty_wire_value_falls_back_to_none(): void
    {
        $this->assertSame(BillingProvider::None, BillingProvider::fromWire(null));
        $this->assertSame(BillingProvider::None, BillingProvider::fromWire(''));
    }

    /**
     * `manual` grants: an operator-granted plan is as entitled as a paid one,
     * it simply has no receipt. Only `none` means nothing backs the plan.
     */
    public function test_grants_is_true_for_every_real_rail_and_false_for_none(): void
    {
        $expected = [
            'none' => false,
            'stripe' => true,
            'app_store' => true,
            'play_store' => true,
            'manual' => true,
        ];

        $this->assertSame(
            array_map(fn (BillingProvider $provider): string => $provider->value, BillingProvider::cases()),
            array_keys($expected),
            'the truth table does not cover every case',
        );

        foreach (BillingProvider::cases() as $provider) {
            $this->assertSame(
                $expected[$provider->value],
                $provider->grants(),
                "grants() is wrong for {$provider->value}",
            );
        }
    }

    /**
     * Exactly the two mobile stores are stores. Stripe is a rail we operate,
     * and `manual` has no storefront at all.
     */
    public function test_is_store_is_true_for_exactly_the_two_mobile_stores(): void
    {
        $expected = [
            'none' => false,
            'stripe' => false,
            'app_store' => true,
            'play_store' => true,
            'manual' => false,
        ];

        $this->assertSame(
            array_map(fn (BillingProvider $provider): string => $provider->value, BillingProvider::cases()),
            array_keys($expected),
            'the truth table does not cover every case',
        );

        foreach (BillingProvider::cases() as $provider) {
            $this->assertSame(
                $expected[$provider->value],
                $provider->isStore(),
                "isStore() is wrong for {$provider->value}",
            );
        }
    }
}
