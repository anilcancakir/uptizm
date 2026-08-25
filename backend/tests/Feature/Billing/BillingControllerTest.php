<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Http\Controllers\Api\V1\BillingController;
use App\Models\Team;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionBuilder;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Exception\ApiConnectionException;
use Tests\TestCase;

/**
 * Locks the team-scoped billing HTTP surface: `checkout`/`swap`/`cancel`/
 * `portal` each unwrap or forward a mocked Cashier call (no live Stripe
 * network access in tests, per research/01's partial-mock guidance), and
 * `GET /billing` reflects the current team's entitlement + subscription.
 *
 * Cross-team isolation is structural rather than a guard clause: every
 * action resolves the acting user's `currentTeam` relation, never a
 * route-supplied team id, so there is no cross-team access vector to mask.
 */
class BillingControllerTest extends TestCase
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
     * A team this rail already bills cannot open a SECOND subscription.
     *
     * `newSubscription()` does not care that one exists, so without the guard a
     * customer who cancels and buys again holds two live Stripe subscriptions
     * and pays both. Measured end to end against a live Stripe test account, and
     * the double charge is not the worst of it: Cashier stores both under
     * `type = 'default'`, so `subscription('default')` becomes ambiguous for
     * `swap`, `cancel` and the period read, and the eventual deletion of the
     * older one revoked the entitlement the newer one was paying for.
     *
     * THE PAIR IS THE TEST, and the accepting limb is what stops this guard
     * being a wall. Keyed on "has any subscription row" it would lock out every
     * customer who has ever subscribed, including one whose subscription
     * genuinely ended, and there is no resume endpoint for them to use.
     */
    public function test_a_team_with_a_live_subscription_cannot_buy_a_second_one(): void
    {
        [$user, $team] = $this->makeTeam();

        // A cancelled subscription inside its paid period still grants, and it
        // is the case this guard exists for.
        $this->makeSubscription($team, 'price_pro');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/checkout', [
            'plan' => Plan::Pro->value,
            'cycle' => 'monthly',
            'success_url' => 'https://app.test/billing?checkout=success',
            'cancel_url' => 'https://app.test/billing?checkout=cancelled',
        ])
            ->assertStatus(409)
            ->assertJsonPath('billing.reason', BillingController::REASON_SUBSCRIPTION_EXISTS)
            ->assertJsonPath('billing.provider', BillingProvider::Stripe->value);

        // The accepting limb: a subscription that no longer grants is not a
        // subscription, and this customer has to be able to buy again. The team
        // mock would fail the test if the controller never reached the rail.
        $team->subscriptions()->update(['stripe_status' => 'canceled']);

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('checkout')->once()->andReturn(
            new Checkout($team, StripeCheckoutSession::constructFrom([
                'id' => 'cs_test_after_cancel',
                'url' => 'https://checkout.stripe.com/after_cancel',
            ])),
        );

        $mockedTeam = Mockery::mock($team);
        $mockedTeam->shouldReceive('newSubscription')->once()->andReturn($builder);
        $user->setRelation('currentTeam', $mockedTeam);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/checkout', [
            'plan' => Plan::Pro->value,
            'cycle' => 'monthly',
            'success_url' => 'https://app.test/billing?checkout=success',
            'cancel_url' => 'https://app.test/billing?checkout=cancelled',
        ])->assertOk()->assertJsonPath('session_id', 'cs_test_after_cancel');
    }

    /**
     * A granting subscription of ANOTHER Cashier type does not block a checkout.
     *
     * The guard iterates the team's Cashier rows, and Cashier's named types are
     * a first-class feature: a row under any other type is one `swap` and
     * `cancel` cannot reach, since both resolve `subscription('default')` and
     * Cashier filters that by type. So refusing here would close the escape
     * hatch the refusal itself points at: `swap` would find nothing, answer 409
     * `no_subscription`, and that customer could not buy at all.
     *
     * The negative control the pair above cannot provide: both of its limbs are
     * `default` rows, so an unscoped guard passes it.
     */
    public function test_a_granting_subscription_of_another_type_does_not_block_a_checkout(): void
    {
        [$user, $team] = $this->makeTeam();

        $this->makeSubscription($team, 'price_pro')->update(['type' => 'seats']);

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('checkout')->once()->andReturn(
            new Checkout($team, StripeCheckoutSession::constructFrom([
                'id' => 'cs_test_other_type',
                'url' => 'https://checkout.stripe.com/other_type',
            ])),
        );

        $mockedTeam = Mockery::mock($team);
        $mockedTeam->shouldReceive('newSubscription')->once()->andReturn($builder);
        $user->setRelation('currentTeam', $mockedTeam);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/checkout', [
            'plan' => Plan::Pro->value,
            'cycle' => 'monthly',
            'success_url' => 'https://app.test/billing?checkout=success',
            'cancel_url' => 'https://app.test/billing?checkout=cancelled',
        ])->assertOk()->assertJsonPath('session_id', 'cs_test_other_type');
    }

    /**
     * Each cycle reaches its own price, and a cycle this deployment does not
     * sell is refused rather than substituted.
     *
     * THE PAIR IS THE TEST. Two prices for one tier makes "pro is sellable"
     * true for both requests, so an implementation that ignored the cycle, or
     * that took whichever price it found first, passes a single-limb assertion
     * and charges the customer the other figure. That is what shipped: a screen
     * offering "Annual, save ~15%" at $29/mo while Stripe billed $34.00 monthly.
     *
     * The refusing limb matters just as much. A tier priced one way only has to
     * REFUSE the other way, because substituting means charging an amount the
     * customer was never shown.
     */
    public function test_each_cycle_checks_out_against_its_own_price(): void
    {
        config(['cashier.plans' => [
            'price_pro_monthly' => ['tier' => Plan::Pro->value, 'cycle' => 'monthly'],
            'price_pro_annual' => ['tier' => Plan::Pro->value, 'cycle' => 'annual'],
            // Sold monthly only, which is what makes the annual refusal below a
            // config fact rather than a client fault.
            'price_business' => Plan::Business->value,
        ]]);

        [$user, $team] = $this->makeTeam();

        foreach ([
            ['monthly', 'price_pro_monthly'],
            ['annual', 'price_pro_annual'],
        ] as [$cycle, $expectedPrice]) {
            $builder = Mockery::mock(SubscriptionBuilder::class);
            $builder->shouldReceive('checkout')->once()->andReturn(
                new Checkout($team, StripeCheckoutSession::constructFrom([
                    'id' => 'cs_test_'.$cycle,
                    'url' => 'https://checkout.stripe.com/'.$cycle,
                ])),
            );

            $mockedTeam = Mockery::mock($team);
            $mockedTeam->shouldReceive('newSubscription')
                ->once()
                ->with('default', $expectedPrice)
                ->andReturn($builder);

            $user->setRelation('currentTeam', $mockedTeam);
            Sanctum::actingAs($user);

            $this->postJson('/api/v1/billing/checkout', [
                'plan' => Plan::Pro->value,
                'cycle' => $cycle,
                'success_url' => 'https://app.test/billing?checkout=success',
                'cancel_url' => 'https://app.test/billing?checkout=cancelled',
            ])->assertOk()->assertJsonPath('session_id', 'cs_test_'.$cycle);
        }

        // `business` has no annual price, so the request is refused rather than
        // billed at its monthly one. The team mock takes no `newSubscription`
        // expectation here on purpose: Mockery would fail the test if the
        // controller opened a session anyway.
        $unmapped = Mockery::mock($team);
        $unmapped->shouldReceive('newSubscription')->never();
        $user->setRelation('currentTeam', $unmapped);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/billing/checkout', [
            'plan' => Plan::Business->value,
            'cycle' => 'annual',
            'success_url' => 'https://app.test/billing?checkout=success',
            'cancel_url' => 'https://app.test/billing?checkout=cancelled',
        ])->assertStatus(422);

        // And an absent cycle is refused rather than defaulted, which is what
        // stops a client from buying whichever price was listed first.
        $this->postJson('/api/v1/billing/checkout', [
            'plan' => Plan::Pro->value,
            'success_url' => 'https://app.test/billing?checkout=success',
            'cancel_url' => 'https://app.test/billing?checkout=cancelled',
        ])->assertStatus(422)->assertJsonValidationErrors('cycle');
    }

    public function test_checkout_returns_the_unwrapped_url_and_session_id(): void
    {
        [$user, $team] = $this->makeTeam();

        $session = StripeCheckoutSession::constructFrom([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/test_session',
        ]);
        $fakeCheckout = new Checkout($team, $session);

        // The subscription builder, NOT `Billable::checkout()`. Cashier's
        // `Checkout::create` defaults to `mode: payment`, so passing a recurring
        // price to the billable's own `checkout()` is rejected by Stripe with
        // "You specified `payment` mode but passed a recurring price". Every
        // plan sold here is recurring, so that path can never succeed, and this
        // expectation is what keeps it from returning: mocking the old call
        // shape pinned the broken one as correct, and no assertion could fail
        // because Stripe was never asked whether the call was valid.
        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('checkout')
            ->once()
            ->with([
                'success_url' => 'https://app.test/billing?checkout=success',
                'cancel_url' => 'https://app.test/billing?checkout=cancelled',
            ])
            ->andReturn($fakeCheckout);

        $mockedTeam = Mockery::mock($team);
        $mockedTeam->shouldReceive('newSubscription')
            ->once()
            ->with('default', 'price_pro')
            ->andReturn($builder);

        $user->setRelation('currentTeam', $mockedTeam);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/billing/checkout', [
            'plan' => Plan::Pro->value,
            'cycle' => 'monthly',
            'success_url' => 'https://app.test/billing?checkout=success',
            'cancel_url' => 'https://app.test/billing?checkout=cancelled',
        ]);

        $response->assertOk();
        $response->assertJson([
            'checkout_url' => 'https://checkout.stripe.com/test_session',
            'session_id' => 'cs_test_123',
        ]);
    }

    public function test_checkout_rejects_an_unmapped_plan(): void
    {
        // Only "pro" is mapped to a Stripe price; a validly-enumerated but
        // unmapped tier must be rejected before any Cashier call is attempted.
        config(['cashier.plans' => ['price_pro' => Plan::Pro->value]]);

        [$user] = $this->makeTeam();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/billing/checkout', [
            'plan' => Plan::Business->value,
            'cycle' => 'monthly',
            'success_url' => 'https://app.test/billing?checkout=success',
            'cancel_url' => 'https://app.test/billing?checkout=cancelled',
        ]);

        $response->assertStatus(422);
    }

    public function test_swap_changes_the_subscription_price_and_returns_the_stored_entitlement(): void
    {
        [$user, $team] = $this->makeTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_product_id' => 'price_pro',
        ]);
        $subscription = $this->makeSubscription($team, 'price_pro');

        $mockedSubscription = Mockery::mock($subscription);
        $mockedSubscription->shouldReceive('swap')
            ->once()
            ->with('price_business')
            ->andReturnUsing(function () use ($subscription) {
                $subscription->forceFill(['stripe_price' => 'price_business'])->save();

                return $subscription;
            });

        $team->setRelation('subscriptions', collect([$mockedSubscription]));
        $user->setRelation('currentTeam', $team);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/billing/swap', ['plan' => Plan::Business->value, 'cycle' => 'monthly']);

        $response->assertOk();

        /*
         * The Cashier row now says `price_business` and the wire still says
         * `price_pro`, which is the seam working rather than a stale read: the
         * wire reports `teams.plan_*`, and the only writer of those columns is
         * the entitlement action a rail's event drives
         * (App\Actions\Billing\WriteTeamEntitlement). The swap's effect reaches
         * the wire when Stripe's `customer.subscription.updated` arrives.
         */
        $response->assertJsonPath('data.product_id', 'price_pro');
        $this->assertSame('price_business', $subscription->refresh()->stripe_price);
    }

    public function test_swap_returns_404_without_an_active_subscription(): void
    {
        [$user] = $this->makeTeam();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/billing/swap', ['plan' => Plan::Business->value, 'cycle' => 'monthly']);

        $response->assertStatus(404);
    }

    public function test_cancel_marks_the_subscription_to_end_at_period_end(): void
    {
        $periodEnd = now()->addDays(14)->startOfSecond();

        [$user, $team] = $this->makeTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_current_period_end' => $periodEnd,
            'plan_renews' => true,
        ]);
        $subscription = $this->makeSubscription($team, 'price_pro');

        $mockedSubscription = Mockery::mock($subscription);
        $mockedSubscription->shouldReceive('cancel')
            ->once()
            ->andReturnUsing(function () use ($subscription) {
                $subscription->forceFill([
                    'stripe_status' => 'canceled',
                    'ends_at' => now()->addDays(14),
                ])->save();

                return $subscription;
            });

        $team->setRelation('subscriptions', collect([$mockedSubscription]));
        $user->setRelation('currentTeam', $team);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/billing/cancel');

        $response->assertOk();

        /*
         * Cashier's `ends_at` is gone from the wire, and its replacement is not
         * a rename: `current_period_end` is when the paid period ends whether or
         * not it renews, and `renews` is the separate answer to whether it rolls
         * over. Cancelling flips `renews` when the rail says so, so both fields
         * still read as they did until the webhook lands.
         */
        $response->assertJsonMissingPath('data.ends_at');
        $response->assertJsonPath('data.current_period_end', $periodEnd->toIso8601String());
        $response->assertJsonPath('data.renews', true);
        $this->assertNotNull($subscription->refresh()->ends_at);
    }

    public function test_cancel_returns_404_without_an_active_subscription(): void
    {
        [$user] = $this->makeTeam();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/billing/cancel');

        $response->assertStatus(404);
    }

    public function test_portal_returns_the_portal_url(): void
    {
        [$user, $team] = $this->makeTeam();

        $mockedTeam = Mockery::mock($team);
        $mockedTeam->shouldReceive('billingPortalUrl')
            ->once()
            ->with('https://app.test/billing')
            ->andReturn('https://billing.stripe.com/session/test');

        $user->setRelation('currentTeam', $mockedTeam);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing/portal?return_url=https://app.test/billing');

        $response->assertOk();
        $response->assertJson(['portal_url' => 'https://billing.stripe.com/session/test']);
    }

    public function test_show_returns_the_stored_entitlement_rather_than_the_cashier_row(): void
    {
        [$user, $team] = $this->makeTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_product_id' => 'price_pro',
        ]);

        // A Cashier row whose price DISAGREES with the entitlement columns. The
        // wire reports the columns, because `teams.plan*` is the entitlement
        // truth and Cashier is one feeder of it rather than the truth itself.
        $this->makeSubscription($team, 'price_legacy');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing');

        $response->assertOk();
        $response->assertJsonPath('data.plan', 'pro');
        $response->assertJsonPath('data.provider', 'stripe');
        $response->assertJsonPath('data.product_id', 'price_pro');
    }

    /**
     * The Stripe rail, with a customer: `manage_via` is `portal`.
     *
     * Asserted as the whole payload with {@see TestResponse::assertExactJson()}
     * rather than path by path, because this shape is a published contract and an
     * ADDED key breaks a strict decoder exactly as a removed one does. Only an
     * exact assertion sees the addition.
     */
    public function test_show_emits_the_neutral_wire_for_a_stripe_team_with_a_customer(): void
    {
        $periodEnd = now()->addDays(20)->startOfSecond();
        $trialEnd = now()->addDays(6)->startOfSecond();

        [$user, $team] = $this->makeTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_provider_status' => 'active',
            'plan_product_id' => 'price_pro',
            'plan_current_period_end' => $periodEnd,
            'plan_renews' => true,
            // Junk on purpose. No Stripe feeder writes this column, because a
            // billing-portal URL is a short-lived single-use session; a value
            // that somehow got in must still never reach the wire.
            'plan_manage_url' => 'https://billing.stripe.com/session/stale',
        ]);

        $this->makeSubscription($team, 'price_pro', $trialEnd);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing');

        $response->assertOk();
        $response->assertExactJson([
            'data' => [
                'plan' => 'pro',
                'plan_status' => 'active',
                'subscribed' => true,
                'renews' => true,
                'cycle' => 'monthly',
                'provider' => 'stripe',
                'provider_status' => 'active',
                'product_id' => 'price_pro',
                'manage_via' => 'portal',
                // Null on the web rail by design: the client calls
                // `GET /billing/portal`, which mints the session live.
                'manage_url' => null,
                'current_period_end' => $periodEnd->toIso8601String(),
                'trial_ends_at' => $trialEnd->toIso8601String(),
                'grace_period_ends_at' => null,
                'ai_analysis_trials_remaining' => null,
            ],
        ]);
    }

    /**
     * The App Store rail: `manage_via` is `app_store` and `product_id` is the
     * store's product, never the Stripe price a migrated team still carries.
     */
    public function test_show_points_an_app_store_team_at_the_store_and_not_at_stripe(): void
    {
        $periodEnd = now()->addDays(11)->startOfSecond();

        [$user, $team] = $this->makeTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => PlanStatus::Trialing->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_provider_status' => 'in_trial',
            'plan_product_id' => 'uptizm_pro_monthly',
            'plan_current_period_end' => $periodEnd,
            'plan_renews' => true,
            'plan_manage_url' => 'https://apps.apple.com/account/subscriptions',
        ]);

        /*
         * A web-to-store migration leaves the team holding a Stripe customer id
         * AND an old Cashier row while the store bills it. Neither may steer a
         * store subscriber back to the portal, and neither may put a Stripe
         * price on the wire: the rail on record decides both.
         */
        $this->makeSubscription($team, 'price_pro');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing');

        $response->assertOk();
        $response->assertExactJson([
            'data' => [
                'plan' => 'pro',
                'plan_status' => 'trialing',
                'subscribed' => true,
                'renews' => true,
                'cycle' => null,
                'provider' => 'app_store',
                'provider_status' => 'in_trial',
                'product_id' => 'uptizm_pro_monthly',
                'manage_via' => 'app_store',
                'manage_url' => 'https://apps.apple.com/account/subscriptions',
                'current_period_end' => $periodEnd->toIso8601String(),
                // Stripe-only by construction: it is read from Cashier's local
                // `subscriptions.trial_ends_at`, and the store rail's own trial
                // arrives as `plan_status: trialing` plus the period end.
                'trial_ends_at' => null,
                'grace_period_ends_at' => null,
                'ai_analysis_trials_remaining' => null,
            ],
        ]);
    }

    /**
     * The Play Store rail, mid grace period: `manage_via` is `play_store`, and
     * a dunning status still entitles.
     */
    public function test_show_points_a_play_store_team_at_the_store_through_its_grace_period(): void
    {
        $periodEnd = now()->addDays(3)->startOfSecond();
        $graceEnd = now()->addDays(5)->startOfSecond();

        [$user] = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Grace->value,
            'plan_provider' => BillingProvider::PlayStore->value,
            'plan_provider_status' => 'billing_issue_detected_at',
            'plan_product_id' => 'uptizm_business_annual',
            'plan_current_period_end' => $periodEnd,
            'plan_renews' => false,
            'plan_grace_period_ends_at' => $graceEnd,
            'plan_manage_url' => 'https://play.google.com/store/account/subscriptions',
            // A store-sold team has never had a Stripe customer.
            'stripe_id' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing');

        $response->assertOk();
        $response->assertExactJson([
            'data' => [
                'plan' => 'business',
                'plan_status' => 'grace',
                // Grace grants: the charge has not landed yet and the plan is
                // still owed (PlanStatus::grants()).
                'subscribed' => true,
                'renews' => false,
                'cycle' => null,
                'provider' => 'play_store',
                'provider_status' => 'billing_issue_detected_at',
                'product_id' => 'uptizm_business_annual',
                'manage_via' => 'play_store',
                'manage_url' => 'https://play.google.com/store/account/subscriptions',
                'current_period_end' => $periodEnd->toIso8601String(),
                'trial_ends_at' => null,
                'grace_period_ends_at' => $graceEnd->toIso8601String(),
                'ai_analysis_trials_remaining' => null,
            ],
        ]);
    }

    /**
     * A Stripe team with NO Stripe customer gets `none`, not `portal`.
     *
     * Measured, not defensive: `billingPortalUrl()` calls
     * `assertCustomerExists()` as its first line
     * (vendor/laravel/cashier/src/Concerns/ManagesCustomer.php:607), which
     * throws when `hasStripeId()` is false. Emitting `portal` here would be the
     * wire telling the client to call an endpoint that cannot answer.
     */
    public function test_show_withholds_the_portal_from_a_stripe_team_without_a_customer(): void
    {
        [$user] = $this->makeTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_product_id' => 'price_pro',
            'stripe_id' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing');

        $response->assertOk();
        $response->assertExactJson([
            'data' => [
                'plan' => 'pro',
                'plan_status' => 'active',
                'subscribed' => true,
                // Unknown rather than false: no rail has said whether this
                // subscription rolls over.
                'renews' => null,
                'cycle' => 'monthly',
                'provider' => 'stripe',
                'provider_status' => null,
                'product_id' => 'price_pro',
                'manage_via' => 'none',
                'manage_url' => null,
                'current_period_end' => null,
                'trial_ends_at' => null,
                'grace_period_ends_at' => null,
                'ai_analysis_trials_remaining' => null,
            ],
        ]);
    }

    /**
     * A never-billed team: the five non-null fields hold their defaults and the
     * other eight are null.
     */
    public function test_show_emits_the_never_billed_defaults_for_a_free_team(): void
    {
        [$user] = $this->makeTeam([
            'plan' => Plan::Free->value,
            'plan_status' => null,
            'stripe_id' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing');

        $response->assertOk();
        $response->assertExactJson([
            'data' => [
                'plan' => 'free',
                // `none`, never null: an absent lifecycle is a value in the
                // neutral vocabulary, so the client never decodes a null here.
                'plan_status' => 'none',
                'subscribed' => false,
                'renews' => null,
                'cycle' => null,
                'provider' => 'none',
                'provider_status' => null,
                'product_id' => null,
                'manage_via' => 'none',
                'manage_url' => null,
                'current_period_end' => null,
                'trial_ends_at' => null,
                'grace_period_ends_at' => null,
                // Carried over unchanged from the old payload: the Free tier
                // meters AI monitor setups instead of entitling them.
                'ai_analysis_trials_remaining' => 3,
            ],
        ]);
    }

    /**
     * `manage_via` is a function of the RAIL, so every rail with nowhere to send
     * the customer reads `none` even when the team has a Stripe customer and a
     * stored manage url.
     */
    public function test_manage_via_is_none_for_every_rail_with_nowhere_to_send_the_customer(): void
    {
        // `crypto` stands for a rail a newer backend ships and this build has
        // never heard of: BillingProvider::fromWire() lands it on `none`, so an
        // unknown rail steers nobody anywhere rather than guessing a surface.
        $rails = [
            'a null provider' => null,
            'the none provider' => BillingProvider::None->value,
            'an operator grant' => BillingProvider::Manual->value,
            'an unknown rail' => 'crypto',
        ];

        foreach ($rails as $label => $provider) {
            [$user] = $this->makeTeam([
                'plan' => Plan::Pro->value,
                'plan_status' => PlanStatus::Active->value,
                'plan_provider' => $provider,
                'plan_manage_url' => 'https://example.test/manage',
            ]);

            Sanctum::actingAs($user);

            $response = $this->getJson('/api/v1/billing');

            $response->assertOk();
            $this->assertSame(
                'none',
                $response->json('data.manage_via'),
                "{$label} must not name a management surface",
            );
            $this->assertNull(
                $response->json('data.manage_url'),
                "{$label} must not pass a stored manage url through",
            );
        }
    }

    /**
     * The two failures `paymentMethod()` must tell apart: a Stripe outage
     * versus a team that genuinely has no card on file. Before `available`
     * existed the two bodies were byte-identical, so a single-path test
     * could not fail for the right reason; this drives both against one
     * team and asserts the bodies DIFFER, then checks each `available`
     * value and that the four original card fields kept their names.
     */
    public function test_payment_method_tells_a_stripe_outage_apart_from_no_card_on_file(): void
    {
        [$user, $team] = $this->makeTeam();

        $mockedTeamWithNoCard = Mockery::mock($team);
        $mockedTeamWithNoCard->shouldReceive('defaultPaymentMethod')
            ->once()
            ->andReturn(null);

        $user->setRelation('currentTeam', $mockedTeamWithNoCard);
        Sanctum::actingAs($user);

        $noCardResponse = $this->getJson('/api/v1/billing/payment-method');

        $noCardResponse->assertOk();
        $noCardResponse->assertExactJson([
            'available' => true,
            'renewal_date' => null,
            'brand' => null,
            'last4' => null,
            'exp_month' => null,
            'exp_year' => null,
        ]);

        $mockedTeamDuringOutage = Mockery::mock($team);
        $mockedTeamDuringOutage->shouldReceive('defaultPaymentMethod')
            ->once()
            ->andThrow(new ApiConnectionException('Could not connect to Stripe.'));

        $user->setRelation('currentTeam', $mockedTeamDuringOutage);
        Sanctum::actingAs($user);

        $outageResponse = $this->getJson('/api/v1/billing/payment-method');

        $outageResponse->assertOk();
        $outageResponse->assertExactJson([
            'available' => false,
            'renewal_date' => null,
            'brand' => null,
            'last4' => null,
            'exp_month' => null,
            'exp_year' => null,
        ]);

        // The whole point of the step: the two bodies must not be
        // byte-identical, which they were before `available` existed.
        $this->assertNotEquals($noCardResponse->json(), $outageResponse->json());
    }

    public function test_billing_is_isolated_per_team(): void
    {
        [, $teamA] = $this->makeTeam(['plan' => Plan::Free->value]);
        [$userB, $teamB] = $this->makeTeam(['plan' => Plan::Business->value, 'plan_status' => 'active']);
        $this->makeSubscription($teamB, 'price_business');

        Sanctum::actingAs($userB);

        $response = $this->getJson('/api/v1/billing');

        $response->assertOk();
        $response->assertJsonPath('data.plan', 'business');
        $this->assertNotSame($teamA->id, $teamB->id);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/billing');

        $response->assertStatus(401);
    }

    /**
     * Build a persisted team + owning user, with the team set as current.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: Team}
     */
    protected function makeTeam(array $overrides = []): array
    {
        $user = User::factory()->create();

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Ops Team',
            'personal_team' => true,
            'stripe_id' => 'cus_'.Str::random(14),
            'plan' => Plan::Free->value,
            'plan_status' => null,
            ...$overrides,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        // Billing acts on an already-existing team; without this the
        // resource response defaults to 201 for the freshly-created model.
        $team->wasRecentlyCreated = false;

        return [$user, $team];
    }

    /**
     * Build a persisted "default" subscription for the given team.
     *
     * `$trialEndsAt` writes Cashier's local `subscriptions.trial_ends_at`
     * column, which is the one Cashier field the entitlement wire still reads;
     * it is a plain column read, never a Stripe call.
     */
    protected function makeSubscription(
        Team $team,
        string $priceId,
        ?DateTimeInterface $trialEndsAt = null,
    ): Subscription {
        return Subscription::query()->create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(10),
            'stripe_status' => 'active',
            'stripe_price' => $priceId,
            'trial_ends_at' => $trialEndsAt,
        ]);
    }
}
