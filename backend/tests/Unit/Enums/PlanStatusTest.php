<?php

namespace Tests\Unit\Enums;

use App\Enums\PlanStatus;
use PHPUnit\Framework\TestCase;

/**
 * The status half of the neutral billing vocabulary.
 *
 * WHY THIS FILE PINS THE CASE SET ITSELF
 *
 * Same reason as the provider enum: a public client package decodes these
 * wire values and an installed build cannot be recalled, so the exact value
 * list is asserted rather than derived from `cases()`. `paused` is in the
 * list even though only one of the two mobile stores has a pause primitive,
 * because adding it after clients ship is a wire break and modelling it now
 * costs one case.
 *
 * WHY THE GRANT TABLE IS THE LOAD-BEARING TEST
 *
 * `grants()` is the predicate that decides whether a paying team keeps its
 * plan, and its two error directions are not symmetric: a wrong `false`
 * revokes a customer who paid, a wrong `true` gives away a tier. The dunning
 * statuses are the ones worth stating explicitly, because "the charge has not
 * landed yet" reads like a revocation and is not one. The table is keyed by
 * wire value and asserted to cover every case, so a ninth status has to be
 * decided here rather than inheriting a silent default.
 */
class PlanStatusTest extends TestCase
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
                'trialing',
                'active',
                'past_due',
                'grace',
                'canceled',
                'expired',
                'paused',
            ],
            array_map(fn (PlanStatus $status): string => $status->value, PlanStatus::cases()),
        );
    }

    /** Every case decodes from its own wire value, so a round trip is lossless. */
    public function test_every_case_round_trips_through_from_wire(): void
    {
        foreach (PlanStatus::cases() as $status) {
            $this->assertSame(
                $status,
                PlanStatus::fromWire($status->value),
                "{$status->value} did not round trip",
            );
        }
    }

    /**
     * A rail's own dialect is not this vocabulary. `incomplete_expired` is
     * Stripe's word and `cancelled` is the other spelling; neither is a case
     * here, and both must fall back rather than guess a neighbour.
     */
    public function test_an_unknown_wire_value_falls_back_to_none(): void
    {
        $this->assertSame(PlanStatus::None, PlanStatus::fromWire('nonsense'));
        $this->assertSame(PlanStatus::None, PlanStatus::fromWire('incomplete_expired'));
        $this->assertSame(PlanStatus::None, PlanStatus::fromWire('cancelled'));
        $this->assertSame(PlanStatus::None, PlanStatus::fromWire('Active'));
    }

    /** A column that predates any rail is null, and null is not an error. */
    public function test_a_null_or_empty_wire_value_falls_back_to_none(): void
    {
        $this->assertSame(PlanStatus::None, PlanStatus::fromWire(null));
        $this->assertSame(PlanStatus::None, PlanStatus::fromWire(''));
    }

    /**
     * The four granting statuses, and the four that do not. `past_due` and
     * `grace` grant deliberately: both mean the plan is still owed and the
     * money has not arrived yet, and cutting a customer off at the first
     * failed charge punishes an expired card.
     */
    public function test_grants_covers_every_case_and_only_the_four_granting_statuses_are_true(): void
    {
        $expected = [
            'none' => false,
            'trialing' => true,
            'active' => true,
            'past_due' => true,
            'grace' => true,
            'canceled' => false,
            'expired' => false,
            'paused' => false,
        ];

        $this->assertSame(
            array_map(fn (PlanStatus $status): string => $status->value, PlanStatus::cases()),
            array_keys($expected),
            'the truth table does not cover every case',
        );

        foreach (PlanStatus::cases() as $status) {
            $this->assertSame(
                $expected[$status->value],
                $status->grants(),
                "grants() is wrong for {$status->value}",
            );
        }
    }

    /**
     * An unknown status decodes to a NON-granting value, which is the pairing
     * that matters: the fallback and the predicate are separately correct, and
     * together they mean an unrecognised word can never silently entitle.
     */
    public function test_an_unknown_status_does_not_entitle(): void
    {
        $this->assertFalse(PlanStatus::fromWire('unpaid')->grants());
    }
}
