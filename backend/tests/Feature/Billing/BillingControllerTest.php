<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Models\Team;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Stripe\Checkout\Session as StripeCheckoutSession;
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

    public function test_checkout_returns_the_unwrapped_url_and_session_id(): void
    {
        [$user, $team] = $this->makeTeam();

        $session = StripeCheckoutSession::constructFrom([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/test_session',
        ]);
        $fakeCheckout = new Checkout($team, $session);

        $mockedTeam = Mockery::mock($team);
        $mockedTeam->shouldReceive('checkout')
            ->once()
            ->with(['price_pro' => 1], [
                'success_url' => 'https://app.test/billing?checkout=success',
                'cancel_url' => 'https://app.test/billing?checkout=cancelled',
            ])
            ->andReturn($fakeCheckout);

        $user->setRelation('currentTeam', $mockedTeam);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/billing/checkout', [
            'plan' => Plan::Pro->value,
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

        $response = $this->postJson('/api/v1/billing/swap', ['plan' => Plan::Business->value]);

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

        $response = $this->postJson('/api/v1/billing/swap', ['plan' => Plan::Business->value]);

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
