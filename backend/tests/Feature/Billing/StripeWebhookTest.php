<?php

namespace Tests\Feature\Billing;

use App\Enums\Plan;
use App\Http\Controllers\StripeWebhookController;
use App\Models\ProcessedWebhookEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks the Stripe webhook feeder: Stripe is the authoritative, idempotent
 * source that writes the Team `plan`/`plan_status` entitlement column.
 *
 * Three invariants are pinned here: a signed subscription/invoice event syncs
 * the entitlement, a re-delivered event (same `event->id`) is a total no-op via
 * {@see ProcessedWebhookEvent}, and an unsigned request is rejected by Cashier's
 * `VerifyWebhookSignature` middleware.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The webhook signing secret used to sign every test payload.
     */
    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        // The signature middleware only attaches when the secret is set, and the
        // price->plan projection reads the config-driven tier map.
        config([
            'cashier.webhook.secret' => static::WEBHOOK_SECRET,
            'cashier.plans' => [
                'price_pro' => Plan::Pro->value,
                'price_business' => Plan::Business->value,
            ],
        ]);
    }

    public function test_signed_subscription_created_webhook_writes_the_entitlement_column(): void
    {
        $team = $this->makeBillableTeam();

        $response = $this->postSignedWebhook(
            $this->subscriptionEvent('evt_created', 'customer.subscription.created', $team, 'price_pro', 'active'),
        );

        $response->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Pro, $team->plan);
        $this->assertSame('active', $team->plan_status);
    }

    public function test_signed_subscription_updated_swaps_the_entitlement_tier(): void
    {
        $team = $this->makeBillableTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_seed', 'customer.subscription.created', $team, 'price_pro', 'active'),
        )->assertOk();

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_updated', 'customer.subscription.updated', $team, 'price_business', 'active'),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame('active', $team->plan_status);
    }

    public function test_incomplete_expired_subscription_update_downgrades_to_free(): void
    {
        $team = $this->makeBillableTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_incomplete_seed', 'customer.subscription.created', $team, 'price_pro', 'active'),
        )->assertOk();

        // Stripe deletes the subscription on this branch, so Cashier's handler
        // returns null; the entitlement must still downgrade without a 500.
        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_incomplete', 'customer.subscription.updated', $team, 'price_pro', 'incomplete_expired'),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Free, $team->plan);
        $this->assertSame('incomplete_expired', $team->plan_status);
    }

    public function test_subscription_deleted_downgrades_the_team_to_free(): void
    {
        $team = $this->makeBillableTeam();

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_del_seed', 'customer.subscription.created', $team, 'price_pro', 'active'),
        )->assertOk();

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_deleted', 'customer.subscription.deleted', $team, 'price_pro', 'canceled'),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Free, $team->plan);
        $this->assertSame('canceled', $team->plan_status);
    }

    public function test_invoice_payment_succeeded_reaffirms_the_active_entitlement(): void
    {
        $team = $this->makeBillableTeam();

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_inv_seed', 'customer.subscription.created', $team, 'price_pro', 'active'),
        )->assertOk();

        // Simulate entitlement drift, then let a paid invoice re-assert it.
        $team->forceFill(['plan' => Plan::Free->value, 'plan_status' => 'canceled'])->save();

        $this->postSignedWebhook(
            $this->invoiceEvent('evt_invoice', $team),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Pro, $team->plan);
        $this->assertSame('active', $team->plan_status);
    }

    public function test_redelivered_event_is_a_no_op(): void
    {
        $team = $this->makeBillableTeam();

        $event = $this->subscriptionEvent('evt_replay', 'customer.subscription.created', $team, 'price_pro', 'active');

        $this->postSignedWebhook($event)->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Pro, $team->plan);

        // Drift the entitlement, then re-deliver the SAME event id: the dedupe
        // guard must skip processing so the drift survives untouched.
        $team->forceFill(['plan' => Plan::Free->value, 'plan_status' => 'canceled'])->save();

        $this->postSignedWebhook($event)->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Free, $team->plan);
        $this->assertSame('canceled', $team->plan_status);
        $this->assertSame(1, ProcessedWebhookEvent::query()->where('event_id', 'evt_replay')->count());
    }

    public function test_granting_status_with_unmapped_price_leaves_a_paid_team_untouched(): void
    {
        Log::spy();

        $team = $this->makeBillableTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);

        // Seed a synced Pro subscription, then deliver an update whose price id
        // is absent from cashier.plans (a prod config gap). A granting status
        // resolving to Free ONLY because the price is unmapped must NOT revoke
        // the paid tier; the entitlement is left untouched and a warning logged.
        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_gap_seed', 'customer.subscription.created', $team, 'price_pro', 'active'),
        )->assertOk();

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_gap', 'customer.subscription.updated', $team, 'price_unmapped', 'active'),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Pro, $team->plan);
        $this->assertSame('active', $team->plan_status);

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_mid_handler_failure_rolls_back_the_dedup_row_so_a_retry_reprocesses(): void
    {
        $team = $this->makeBillableTeam();

        // A handler that throws AFTER the dedup insert must not leave a
        // processed_webhook_events row behind: otherwise Stripe's retry becomes
        // a permanent no-op and the team keeps a paid tier for free.
        $this->partialMock(StripeWebhookController::class, function (MockInterface $mock): void {
            $mock->shouldAllowMockingProtectedMethods()
                ->shouldReceive('syncEntitlementFromSubscription')
                ->andThrow(new RuntimeException('handler exploded mid-flight'));
        });

        $event = $this->subscriptionEvent('evt_poison', 'customer.subscription.created', $team, 'price_pro', 'active');

        $response = $this->postSignedWebhook($event);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(0, ProcessedWebhookEvent::query()->where('event_id', 'evt_poison')->count());
    }

    public function test_unsigned_request_is_rejected(): void
    {
        $team = $this->makeBillableTeam();

        $payload = json_encode(
            $this->subscriptionEvent('evt_unsigned', 'customer.subscription.created', $team, 'price_pro', 'active'),
        );

        $response = $this->call(
            'POST',
            'stripe/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $this->assertSame(403, $response->getStatusCode());

        $team->refresh();
        $this->assertSame(Plan::Free, $team->plan);
    }

    public function test_queue_retry_after_stays_above_the_worker_timeout(): void
    {
        // A billing/webhook job that outlives retry_after gets re-dispatched to a
        // second worker, double-processing the same Stripe event. The default
        // worker timeout is 60s, so every real connection must exceed it.
        $workerTimeout = 60;

        foreach (['database', 'redis', 'beanstalkd', 'sqs'] as $connection) {
            $retryAfter = config("queue.connections.{$connection}.retry_after");

            if ($retryAfter === null) {
                continue;
            }

            $this->assertGreaterThan(
                $workerTimeout,
                $retryAfter,
                "queue.connections.{$connection}.retry_after must exceed the worker timeout.",
            );
        }
    }

    /**
     * Build a persisted team acting as the Stripe customer.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeBillableTeam(array $overrides = []): Team
    {
        $user = User::factory()->create();

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Ops Team',
            'personal_team' => true,
            'stripe_id' => 'cus_'.Str::random(14),
            'plan' => Plan::Free->value,
            'plan_status' => null,
            ...$overrides,
        ]);
    }

    /**
     * Build a Stripe subscription event payload.
     *
     * @return array<string, mixed>
     */
    protected function subscriptionEvent(string $eventId, string $type, Team $team, string $priceId, string $status): array
    {
        return [
            'id' => $eventId,
            'type' => $type,
            'data' => [
                'object' => [
                    'id' => 'sub_'.Str::random(10),
                    'customer' => $team->stripe_id,
                    'status' => $status,
                    'metadata' => ['type' => 'default'],
                    'items' => [
                        'data' => [
                            [
                                'id' => 'si_'.Str::random(10),
                                'quantity' => 1,
                                'price' => [
                                    'id' => $priceId,
                                    'product' => 'prod_'.Str::random(10),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build a Stripe invoice.payment_succeeded event payload.
     *
     * @return array<string, mixed>
     */
    protected function invoiceEvent(string $eventId, Team $team): array
    {
        return [
            'id' => $eventId,
            'type' => 'invoice.payment_succeeded',
            'data' => [
                'object' => [
                    'id' => 'in_'.Str::random(10),
                    'customer' => $team->stripe_id,
                ],
            ],
        ];
    }

    /**
     * POST a Stripe-signed webhook payload to the Cashier endpoint.
     *
     * @param  array<string, mixed>  $event
     */
    protected function postSignedWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", static::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            'stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $payload,
        );
    }
}
