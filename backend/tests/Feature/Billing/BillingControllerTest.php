<?php

namespace Tests\Feature\Billing;

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

    public function test_swap_changes_the_subscription_price_and_returns_the_updated_entitlement(): void
    {
        [$user, $team] = $this->makeTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);
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
        $response->assertJsonPath('data.stripe_price', 'price_business');
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
        [$user, $team] = $this->makeTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);
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
        $this->assertNotNull($response->json('data.ends_at'));
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

    public function test_show_returns_the_current_entitlement_and_subscription(): void
    {
        [$user, $team] = $this->makeTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);
        $this->makeSubscription($team, 'price_pro');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing');

        $response->assertOk();
        $response->assertJsonPath('data.plan', 'pro');
        $response->assertJsonPath('data.stripe_price', 'price_pro');
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
