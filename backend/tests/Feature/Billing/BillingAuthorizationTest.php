<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Tests\TestCase;

/**
 * Locks who may SPEND on the billing surface, and which of three different
 * refusals each wrong answer earns.
 *
 * The four write endpoints (`checkout`/`swap`/`cancel`/`portal`) move money or
 * end a paid period, so they belong to the team OWNER; the five reads stay open
 * to any member, because a member legitimately sees the plan and the usage the
 * upgrade nudges are built on. Gating the reads too would break those nudges,
 * so this file asserts both halves rather than only the refusals.
 *
 * Three answers are deliberately distinguished, and the distinction is the
 * point: a caller who may not act at all gets 403 BEFORE anything about the
 * subscription is looked up, a subscription that exists on a rail we do not
 * control gets a machine-readable 409, and a team with no subscription at all
 * keeps its 404. "You may not", "not ours to change" and "there is nothing
 * there" are three different facts and a client that cannot tell them apart
 * cannot render the right next step for any of them.
 */
class BillingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.plans' => [
            'price_pro' => Plan::Pro->value,
            'price_business' => Plan::Business->value,
        ]]);
    }

    /**
     * The reproducer the whole step exists for: on HEAD a plain member could
     * end the team's paid period, and the mocked `cancel()` writes through so
     * the assertion below measures the actual cancellation rather than a status
     * code that merely looks wrong.
     */
    public function test_a_member_who_does_not_own_the_team_cannot_cancel(): void
    {
        [, $team] = $this->makeOwnedTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);
        $member = $this->attachMember($team);
        $subscription = $this->makeSubscription($team, 'price_pro');

        $mockedSubscription = Mockery::mock($subscription);
        $mockedSubscription->shouldReceive('cancel')
            ->andReturnUsing(function () use ($subscription) {
                $subscription->forceFill([
                    'stripe_status' => 'canceled',
                    'ends_at' => now()->addDays(14),
                ])->save();

                return $subscription;
            });

        $team->setRelation('subscriptions', collect([$mockedSubscription]));
        $member->setRelation('currentTeam', $team);
        Sanctum::actingAs($member);

        $response = $this->postJson('/api/v1/billing/cancel');

        // Asserted BEFORE the status code on purpose: this is the assertion
        // whose failure names the actual damage ("the subscription ended"),
        // where a bare status mismatch only says the answer was the wrong
        // shape. The refusal has to be the whole refusal.
        $subscription->refresh();
        $this->assertNull($subscription->ends_at);
        $this->assertSame('active', $subscription->stripe_status);

        $response->assertStatus(403);
    }

    public function test_a_member_who_does_not_own_the_team_cannot_swap(): void
    {
        [, $team] = $this->makeOwnedTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);
        $member = $this->attachMember($team);
        $subscription = $this->makeSubscription($team, 'price_pro');

        $mockedSubscription = Mockery::mock($subscription);
        $mockedSubscription->shouldReceive('swap')
            ->andReturnUsing(function (string $priceId) use ($subscription) {
                $subscription->forceFill(['stripe_price' => $priceId])->save();

                return $subscription;
            });

        $team->setRelation('subscriptions', collect([$mockedSubscription]));
        $member->setRelation('currentTeam', $team);
        Sanctum::actingAs($member);

        $response = $this->postJson('/api/v1/billing/swap', ['plan' => Plan::Business->value, 'cycle' => 'monthly']);

        $response->assertStatus(403);
        $this->assertSame('price_pro', $subscription->refresh()->stripe_price);
    }

    public function test_a_member_who_does_not_own_the_team_cannot_start_checkout(): void
    {
        [, $team] = $this->makeOwnedTeam();
        $member = $this->attachMember($team);

        // Mocked rather than left to fail: without this a refused request and a
        // Stripe network error are the same 500, and only one of them is the
        // defect under test.
        $mockedTeam = Mockery::mock($team);
        $mockedTeam->shouldReceive('checkout')->andReturn(new Checkout($team, StripeCheckoutSession::constructFrom([
            'id' => 'cs_test_member',
            'url' => 'https://checkout.stripe.com/test_member',
        ])));

        $member->setRelation('currentTeam', $mockedTeam);
        Sanctum::actingAs($member);

        $response = $this->postJson('/api/v1/billing/checkout', [
            'plan' => Plan::Pro->value,
            'cycle' => 'monthly',
            'success_url' => 'https://app.test/billing?checkout=success',
            'cancel_url' => 'https://app.test/billing?checkout=cancelled',
        ]);

        $response->assertStatus(403);
    }

    public function test_a_member_who_does_not_own_the_team_cannot_open_the_billing_portal(): void
    {
        [, $team] = $this->makeOwnedTeam();
        $member = $this->attachMember($team);

        $mockedTeam = Mockery::mock($team);
        $mockedTeam->shouldReceive('billingPortalUrl')->andReturn('https://billing.stripe.com/session/test');

        $member->setRelation('currentTeam', $mockedTeam);
        Sanctum::actingAs($member);

        $response = $this->getJson('/api/v1/billing/portal?return_url=https://app.test/billing');

        $response->assertStatus(403);
    }

    /**
     * The other half of the gate, and the one an over-eager fix breaks: a
     * member still reads every one of the five read endpoints.
     *
     * The team is created with a NULL `stripe_id` on purpose, so `invoices` and
     * `payment-method` answer from Cashier's own no-customer short circuit
     * instead of reaching for the network.
     */
    public function test_a_member_reads_every_billing_endpoint(): void
    {
        [, $team] = $this->makeOwnedTeam(['stripe_id' => null]);
        $member = $this->attachMember($team);

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/billing')->assertOk();
        $this->getJson('/api/v1/billing/plans')->assertOk();
        $this->getJson('/api/v1/billing/usage')->assertOk();
        $this->getJson('/api/v1/billing/invoices')->assertOk();
        $this->getJson('/api/v1/billing/payment-method')->assertOk();
    }

    public function test_the_owner_still_cancels(): void
    {
        [$owner, $team] = $this->makeOwnedTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);
        $subscription = $this->makeSubscription($team, 'price_pro');

        $mockedSubscription = Mockery::mock($subscription);
        $mockedSubscription->shouldReceive('cancel')
            ->once()
            ->andReturnUsing(function () use ($subscription) {
                $subscription->forceFill(['ends_at' => now()->addDays(14)])->save();

                return $subscription;
            });

        $team->setRelation('subscriptions', collect([$mockedSubscription]));
        $owner->setRelation('currentTeam', $team);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/billing/cancel')->assertOk();
        $this->assertNotNull($subscription->refresh()->ends_at);
    }

    /**
     * A store-sold subscription cannot be cancelled by us, and saying "there is
     * nothing to cancel" is a different and wrong claim: the subscription very
     * much exists, it is simply on a rail whose purchase the store manages.
     */
    public function test_cancel_conflicts_when_a_store_owns_the_subscription(): void
    {
        [$owner] = $this->makeOwnedTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::AppStore->value,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/billing/cancel');

        $response->assertStatus(409);
        $response->assertJsonPath('billing.reason', 'managed_by_store');
        $response->assertJsonPath('billing.provider', BillingProvider::AppStore->value);
    }

    public function test_swap_conflicts_when_a_store_owns_the_subscription(): void
    {
        [$owner] = $this->makeOwnedTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::PlayStore->value,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/billing/swap', ['plan' => Plan::Business->value, 'cycle' => 'monthly']);

        $response->assertStatus(409);
        $response->assertJsonPath('billing.reason', 'managed_by_store');
        $response->assertJsonPath('billing.provider', BillingProvider::PlayStore->value);
    }

    /**
     * Starting a Stripe checkout for a store-billed team is the double-charge
     * this whole provenance layer exists to notice, so refuse it at the point
     * of sale rather than warn about it after the money has moved.
     *
     * The client hides this CTA, but a client gate is an affordance and not the
     * enforcement: the same rule that puts the 403 on the server puts this here.
     */
    public function test_checkout_conflicts_when_a_store_owns_the_subscription(): void
    {
        [$owner] = $this->makeOwnedTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::AppStore->value,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/billing/checkout', [
            'plan' => Plan::Business->value,
            'cycle' => 'monthly',
            'success_url' => 'https://app.test/billing?checkout=success',
            'cancel_url' => 'https://app.test/billing?checkout=cancelled',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('billing.reason', 'managed_by_store');
        $response->assertJsonPath('billing.provider', BillingProvider::AppStore->value);
    }

    /**
     * A store-billed team keeps whatever `stripe_id` an earlier web
     * subscription left behind, so `hasStripeId()` is true and the portal would
     * open, pointing the customer at a Stripe subscription that is not the one
     * charging them. The rail is the more specific fact, so it is checked
     * first: `managed_by_store`, not `no_billing_account`.
     */
    public function test_portal_conflicts_when_a_store_owns_the_subscription(): void
    {
        [$owner, $team] = $this->makeOwnedTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::PlayStore->value,
        ]);

        // The precondition that makes this a real case rather than a repeat of
        // the customer-less 409: the legacy Stripe customer is still there.
        $this->assertTrue($team->hasStripeId());

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/billing/portal');

        $response->assertStatus(409);
        $response->assertJsonPath('billing.reason', 'managed_by_store');
        $response->assertJsonPath('billing.provider', BillingProvider::PlayStore->value);
    }

    /**
     * The 409 sits BESIDE the 404 rather than replacing it: on the card rail,
     * no subscription row still means there is nothing to cancel.
     */
    public function test_cancel_still_returns_404_on_the_card_rail_with_no_subscription(): void
    {
        [$owner] = $this->makeOwnedTeam(['plan_provider' => BillingProvider::Stripe->value]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/billing/cancel')->assertStatus(404);
    }

    /**
     * A team that has never been charged has no Stripe customer, and Cashier's
     * `billingPortalUrl()` opens with `assertCustomerExists()`, so forwarding
     * to it unguarded was a 500 on the most ordinary state a team can be in.
     */
    public function test_portal_conflicts_when_the_team_has_no_billing_account(): void
    {
        [$owner] = $this->makeOwnedTeam(['stripe_id' => null]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/billing/portal?return_url=https://app.test/billing');

        $response->assertStatus(409);
        $response->assertJsonPath('billing.reason', 'no_billing_account');
        $response->assertJsonPath('billing.provider', BillingProvider::None->value);
    }

    /**
     * The house 404 mask, applied to the one cross-team vector this surface
     * has: `current_team_id` is a plain column and survives a membership being
     * removed, so a stale pointer must read as a team that is not there rather
     * than as a permission refusal, which would confirm it is.
     */
    public function test_a_user_who_no_longer_belongs_to_their_current_team_is_masked_as_404(): void
    {
        [, $team] = $this->makeOwnedTeam();

        $outsider = User::factory()->create();
        $outsider->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($outsider);

        $this->getJson('/api/v1/billing')->assertStatus(404);
        $this->postJson('/api/v1/billing/cancel')->assertStatus(404);
    }

    /**
     * Build a persisted team plus the user who owns it, with the team set as
     * that user's current team.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: Team}
     */
    protected function makeOwnedTeam(array $overrides = []): array
    {
        $owner = User::factory()->create();

        $team = Team::query()->create([
            'user_id' => $owner->id,
            'name' => 'Ops Team',
            'personal_team' => true,
            'stripe_id' => 'cus_'.Str::random(14),
            'plan' => Plan::Free->value,
            'plan_status' => null,
            ...$overrides,
        ]);

        $owner->forceFill(['current_team_id' => $team->id])->save();

        // Billing acts on an already-existing team; without this the resource
        // response defaults to 201 for the freshly-created model.
        $team->wasRecentlyCreated = false;

        return [$owner, $team];
    }

    /**
     * Attach a non-owning member to the team and make it their current team.
     */
    protected function attachMember(Team $team, string $role = 'member'): User
    {
        $member = User::factory()->create();

        $team->users()->attach($member->id, ['role' => $role]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        return $member;
    }

    /**
     * Build a persisted "default" subscription for the given team.
     */
    protected function makeSubscription(Team $team, string $priceId): Subscription
    {
        return Subscription::query()->create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(10),
            'stripe_status' => 'active',
            'stripe_price' => $priceId,
        ]);
    }
}
