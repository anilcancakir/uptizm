<?php

namespace Tests\Feature\Billing;

use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Support\Billing\StripeSubscriptionState;
use App\Support\TeamKey;
use Tests\TestCase;

/**
 * The rules more than one feeder applies, and the gate that keeps them shared.
 *
 * Three of them had grown a second copy: `$grantingStatuses` and `mapStatus()`
 * in both the Stripe webhook controller and the hourly reconciler, and
 * `looksLikeATeamKey()` in the reconciler and the RevenueCat re-read job. Every
 * copy was correct on the day it was written, and the reconciler's docblock even
 * CITED the controller's array as the list it was kept in step with, which is a
 * claim no code can enforce.
 *
 * The cost is concrete rather than stylistic. Adding `paused` to one
 * `$grantingStatuses` would leave the other revoking every paused subscription
 * on its next hourly run, against every affected team at once, on a schedule,
 * with no failing test anywhere: each copy has its own passing tests, and they
 * would both keep passing while disagreeing.
 *
 * The source assertions below are the part that survives a refactor. Asserting
 * only the shared behaviour would not stop somebody re-adding a private copy
 * next to the shared call and gating on that instead.
 */
class SharedBillingRulesTest extends TestCase
{
    /**
     * Every file that must not carry its own copy, and the members it must not
     * re-declare.
     *
     * @var array<string, array<int, string>>
     */
    private const array MUST_NOT_REDECLARE = [
        'app/Http/Controllers/StripeWebhookController.php' => [
            'grantingStatuses',
            'function mapStatus',
            'function resolvePlanFromPrice',
        ],
        'app/Console/Commands/ReconcileBillingEntitlements.php' => [
            'grantingStatuses',
            'function mapStatus',
            'function planFromPrice',
            'function looksLikeATeamKey',
        ],
        'app/Jobs/SyncRevenueCatEntitlement.php' => [
            'function looksLikeATeamKey',
        ],
    ];

    public function test_no_feeder_carries_its_own_copy_of_a_shared_rule(): void
    {
        foreach (self::MUST_NOT_REDECLARE as $path => $members) {
            $source = file_get_contents(base_path($path));

            // Vacuity guard: an unreadable path would satisfy every assertion
            // below by being an empty string.
            $this->assertIsString($source);
            $this->assertStringContainsString('class ', (string) $source);

            foreach ($members as $member) {
                $this->assertStringNotContainsString(
                    $member,
                    (string) $source,
                    "{$path} declares [{$member}] itself. That rule is shared, and a "
                    .'second copy is free to disagree with the first with every test '
                    .'on both sides still passing.',
                );
            }
        }
    }

    public function test_the_granting_statuses_are_the_three_stripe_grants_under(): void
    {
        // Pinned as a VALUE, not just as a shared reference: the point of the
        // shared table is that changing it changes both feeders, so the table
        // itself is the thing worth a failing test when it moves.
        $this->assertSame(
            ['active', 'trialing', 'past_due'],
            StripeSubscriptionState::GRANTING_STATUSES,
        );

        $this->assertTrue(StripeSubscriptionState::grants('past_due'));
        $this->assertFalse(StripeSubscriptionState::grants('canceled'));
        $this->assertFalse(StripeSubscriptionState::grants('paused'));
    }

    public function test_an_unknown_stripe_status_cannot_entitle_by_accident(): void
    {
        $this->assertSame(
            PlanStatus::Active,
            StripeSubscriptionState::planStatusFor('active'),
        );
        $this->assertSame(
            PlanStatus::Expired,
            StripeSubscriptionState::planStatusFor('incomplete_expired'),
        );

        // The property that matters for a word Stripe adds next year: it falls
        // through to the neutral decoder, which lands on a non-granting status.
        $this->assertFalse(
            StripeSubscriptionState::planStatusFor('some_future_status')->grants(),
        );
    }

    public function test_a_price_id_of_zero_is_looked_up_rather_than_discarded(): void
    {
        // The two copies had already drifted here: one guarded with `! $priceId`,
        // which is true for the string '0'. No Stripe price looks like that, so
        // nothing was broken; two copies of one rule disagreeing about an edge
        // case is how they always start.
        config()->set('cashier.plans', ['0' => Plan::Pro->value]);

        $this->assertSame(Plan::Pro, StripeSubscriptionState::planForPrice('0'));
        $this->assertNull(StripeSubscriptionState::planForPrice(''));
        $this->assertNull(StripeSubscriptionState::planForPrice(null));
        $this->assertNull(StripeSubscriptionState::planForPrice('price_unmapped'));
    }

    public function test_a_team_key_is_judged_against_this_deployments_switch(): void
    {
        config()->set('magic-starter.use_uuids', true);
        $this->assertTrue(TeamKey::looksLikeOne('a26c03f7-e668-4d5f-bb45-f573e1d87088'));
        $this->assertFalse(TeamKey::looksLikeOne('42'));

        // The half a hardcoded UUID check would have broken: a deployment on
        // integer keys must not have every legitimate id refused.
        config()->set('magic-starter.use_uuids', false);
        $this->assertTrue(TeamKey::looksLikeOne('42'));
        $this->assertFalse(TeamKey::looksLikeOne('a26c03f7-e668-4d5f-bb45-f573e1d87088'));
    }
}
