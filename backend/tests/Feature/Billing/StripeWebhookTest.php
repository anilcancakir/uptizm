<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\WriteTeamEntitlement;
use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Http\Controllers\StripeWebhookController;
use App\Models\ProcessedWebhookEvent;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\StripeSubscriptionState;
use Carbon\CarbonImmutable;
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
 *
 * Since the projection runs through {@see WriteTeamEntitlement}, a fourth is
 * pinned alongside them: every write carries its PROVENANCE (which rail claimed
 * the tier, when its event was stamped, the rail's own status word and price id)
 * and is ordered by the event's own `created`. The ordering test is the one that
 * cannot be replaced by reading the code: a feeder passing `now()` instead of
 * the event time satisfies every type check and silently disarms the monotonic
 * rule, and only an out-of-order delivery shows it.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The webhook signing secret used to sign every test payload.
     */
    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    /**
     * The instant every payload's `created` field counts from: 2026-08-22
     * 12:00:00 UTC.
     *
     * Fixed rather than `time()` so an assertion compares against a value the
     * payload states. Any scenario delivering two events to ONE team should
     * space them apart from here, because {@see WriteTeamEntitlement} drops a
     * same-rail write whose event is OLDER than the one on record.
     *
     * A tie is no longer a drop, and the correction matters for anyone writing a
     * test here: an equal timestamp is now decided by DIRECTION, so a second
     * event sharing this instant applies when it is an upgrade and is dropped
     * when it would revoke. That is not a relaxation. Stripe stamps `created` to
     * the second and emits paired events from one API call, so the tie was
     * routinely a paid upgrade losing to a stale re-affirmation of the tier the
     * customer had just left.
     */
    protected const int EVENT_AT = 1787400000;

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

        // The payload carries neither period key, so both columns stay NULL:
        // null is "the rail has not said", and a feeder that defaulted the
        // renew flag to true would be inventing a claim Stripe never made.
        $this->assertNull($team->plan_current_period_end);
        $this->assertNull($team->plan_renews);
    }

    /**
     * A subscription event lands every provenance column it has a source for.
     *
     * A field the feeder forgets has no symptom other than a column that serves
     * null forever, on a rail whose data sat in the payload the whole time. The
     * period end is asserted from the payload for a second reason: reading it
     * off Cashier's `currentPeriodEnd()` instead would be a live Stripe API call
     * per subscription item, inside a webhook handler running in a transaction,
     * and this test configures no Stripe key for it to reach.
     */
    public function test_subscription_created_records_the_full_stripe_provenance(): void
    {
        $team = $this->makeBillableTeam();
        $periodEnd = static::EVENT_AT + 2592000;

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_provenance',
                'customer.subscription.created',
                $team,
                'price_pro',
                'active',
                currentPeriodEnd: $periodEnd,
                cancelAtPeriodEnd: false,
            ),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Pro, $team->plan);
        $this->assertSame(PlanStatus::Active->value, $team->plan_status);
        $this->assertSame(BillingProvider::Stripe->value, $team->plan_provider);
        $this->assertSame(static::EVENT_AT, $team->plan_source_event_at->getTimestamp());
        $this->assertSame('active', $team->plan_provider_status);
        $this->assertSame('price_pro', $team->plan_product_id);
        $this->assertSame($periodEnd, $team->plan_current_period_end->getTimestamp());
        $this->assertTrue($team->plan_renews);

        // Neither column has a source on the Stripe rail: Cashier's grace period
        // is "cancelled but still entitled" rather than a dunning window, and a
        // billing-portal URL is a short-lived single-use session, not a durable
        // value a column can hold.
        $this->assertNull($team->plan_grace_period_ends_at);
        $this->assertNull($team->plan_manage_url);
    }

    /**
     * `cancel_at_period_end` is the renew flag, inverted.
     *
     * Delivered as `customer.subscription.created` deliberately: Cashier's
     * `handleCustomerSubscriptionUpdated` answers a truthy `cancel_at_period_end`
     * by calling `$subscription->currentPeriodEnd()`, which is a live Stripe API
     * call, so the updated event cannot carry this flag in a test at all.
     */
    public function test_a_subscription_cancelling_at_period_end_is_recorded_as_not_renewing(): void
    {
        $team = $this->makeBillableTeam();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_no_renew',
                'customer.subscription.created',
                $team,
                'price_pro',
                'active',
                currentPeriodEnd: static::EVENT_AT + 2592000,
                cancelAtPeriodEnd: true,
            ),
        )->assertOk();

        $team->refresh();
        // Still entitled until the period ends, and the period end is what says
        // until when; the flag only says it will not roll over.
        $this->assertSame(Plan::Pro, $team->plan);
        $this->assertFalse($team->plan_renews);
        $this->assertSame(static::EVENT_AT + 2592000, $team->plan_current_period_end->getTimestamp());
    }

    public function test_signed_subscription_updated_swaps_the_entitlement_tier(): void
    {
        $team = $this->makeBillableTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_seed', 'customer.subscription.created', $team, 'price_pro', 'active'),
        )->assertOk();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_updated',
                'customer.subscription.updated',
                $team,
                'price_business',
                'active',
                created: static::EVENT_AT + 60,
            ),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame('active', $team->plan_status);
    }

    /**
     * The one Stripe status word with no neutral twin.
     *
     * `plan_status` speaks the rail-neutral vocabulary, which has no
     * `incomplete_expired`: an initial payment window that ran out is
     * {@see PlanStatus::Expired}. The rail's own word is not lost, it moves to
     * `plan_provider_status`, which gates nothing and exists for exactly this.
     */
    public function test_incomplete_expired_subscription_update_downgrades_to_free(): void
    {
        $team = $this->makeBillableTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_incomplete_seed', 'customer.subscription.created', $team, 'price_pro', 'active'),
        )->assertOk();

        // Stripe deletes the subscription on this branch, so Cashier's handler
        // returns null; the entitlement must still downgrade without a 500.
        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_incomplete',
                'customer.subscription.updated',
                $team,
                'price_pro',
                'incomplete_expired',
                created: static::EVENT_AT + 60,
            ),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Free, $team->plan);
        $this->assertSame(PlanStatus::Expired->value, $team->plan_status);
        $this->assertSame('incomplete_expired', $team->plan_provider_status);
    }

    /**
     * A status word this build has never seen revokes, and must not be laundered
     * into `active` on the way in.
     *
     * Stripe can add a subscription status at any time. It is absent from
     * {@see StripeSubscriptionState::GRANTING_STATUSES},
     * so the entitlement falls to free; the neutral column
     * takes the enum's safe default rather than guessing, and the unknown word
     * survives verbatim for whoever has to work out what it meant.
     */
    public function test_an_unknown_stripe_status_neither_grants_nor_becomes_active(): void
    {
        $team = $this->makeBillableTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_unknown_seed', 'customer.subscription.created', $team, 'price_pro', 'active'),
        )->assertOk();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_unknown',
                'customer.subscription.updated',
                $team,
                'price_pro',
                'a_status_stripe_added_later',
                created: static::EVENT_AT + 60,
            ),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Free, $team->plan);
        $this->assertSame(PlanStatus::None->value, $team->plan_status);
        $this->assertSame('a_status_stripe_added_later', $team->plan_provider_status);
    }

    /**
     * A late-delivered OLDER event does not overwrite a newer one.
     *
     * This is the assertion that proves the feeder passes the EVENT's own
     * `created` rather than the moment of receipt. Receipt time only ever
     * increases, so a feeder stamping `now()` would read this second delivery as
     * the freshest truth on record and move a Business team back down to Pro,
     * while satisfying every type check and every other test in this file.
     */
    public function test_a_late_delivered_older_event_does_not_overwrite_a_newer_one(): void
    {
        Log::spy();

        $team = $this->makeBillableTeam();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_order_new',
                'customer.subscription.created',
                $team,
                'price_business',
                'active',
                created: static::EVENT_AT + 3600,
            ),
        )->assertOk();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_order_old',
                'customer.subscription.updated',
                $team,
                'price_pro',
                'active',
                created: static::EVENT_AT,
            ),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame(static::EVENT_AT + 3600, $team->plan_source_event_at->getTimestamp());

        // Asserted on the drop's own reason, not merely on "a warning": an
        // unmapped price warns too, and it would pass a weaker assertion while
        // the ordering rule was never reached.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => ($context['reason'] ?? null) === 'stale');
    }

    public function test_subscription_deleted_downgrades_the_team_to_free(): void
    {
        $team = $this->makeBillableTeam();

        $this->postSignedWebhook(
            $this->subscriptionEvent('evt_del_seed', 'customer.subscription.created', $team, 'price_pro', 'active'),
        )->assertOk();

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_deleted',
                'customer.subscription.deleted',
                $team,
                'price_pro',
                'canceled',
                created: static::EVENT_AT + 60,
            ),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Free, $team->plan);
        $this->assertSame('canceled', $team->plan_status);
        $this->assertSame('canceled', $team->plan_provider_status);
        $this->assertSame(BillingProvider::Stripe->value, $team->plan_provider);
        $this->assertSame(static::EVENT_AT + 60, $team->plan_source_event_at->getTimestamp());
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
            $this->invoiceEvent('evt_invoice', $team, created: static::EVENT_AT + 60),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Pro, $team->plan);
        $this->assertSame('active', $team->plan_status);
    }

    /**
     * A paid invoice writes provenance without inventing a period.
     *
     * An invoice object carries no subscription items, so this path has no
     * period to read, and the only other sources are a live Stripe API call
     * inside the handler or a guess. It carries the stored values forward
     * instead, which is what leaves the two period columns as they were: the
     * action writes all ten columns on every apply, so passing nothing here
     * would blank a period the subscription event had already established.
     */
    public function test_invoice_payment_succeeded_keeps_the_period_it_cannot_read(): void
    {
        $team = $this->makeBillableTeam();
        $periodEnd = static::EVENT_AT + 2592000;

        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_inv_period_seed',
                'customer.subscription.created',
                $team,
                'price_pro',
                'active',
                currentPeriodEnd: $periodEnd,
                cancelAtPeriodEnd: false,
            ),
        )->assertOk();

        $this->postSignedWebhook(
            $this->invoiceEvent('evt_inv_period', $team, created: static::EVENT_AT + 60),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Pro, $team->plan);
        $this->assertSame(BillingProvider::Stripe->value, $team->plan_provider);
        $this->assertSame('active', $team->plan_provider_status);
        $this->assertSame('price_pro', $team->plan_product_id);
        $this->assertSame(static::EVENT_AT + 60, $team->plan_source_event_at->getTimestamp());
        $this->assertSame($periodEnd, $team->plan_current_period_end->getTimestamp());
        $this->assertTrue($team->plan_renews);
    }

    /**
     * A paid invoice must not re-attribute another rail's period to Stripe.
     *
     * Carrying the stored period forward is what stops an invoice blanking a
     * period a subscription event established, but it is only meaningful while
     * Stripe is the rail ON RECORD. A web-to-store migration is exactly the
     * case where it is not: a team can hold a store grant and an old Cashier
     * row at the same time, and a Stripe invoice arriving as a cross-rail
     * upgrade would then stamp the STORE's period end under `provider: stripe`.
     * This feeder cannot read Stripe's own period out of an invoice payload,
     * and reading Cashier's accessor for it would be a live Stripe call, so
     * null is the honest answer: the wire says unknown rather than something
     * false.
     */
    public function test_invoice_does_not_carry_another_rails_period_forward(): void
    {
        $team = $this->makeBillableTeam();
        $storePeriodEnd = static::EVENT_AT + 2592000;

        // Seed the Cashier row this path needs, at the HIGHER tier, so the
        // incoming claim is an upgrade and rule 2 lets it land at all.
        $this->postSignedWebhook(
            $this->subscriptionEvent(
                'evt_carry_seed',
                'customer.subscription.created',
                $team,
                'price_business',
                'active',
            ),
        )->assertOk();

        // The store rail then takes the entitlement over at a lower tier, and
        // brings its own period with it.
        $team->forceFill([
            'plan' => Plan::Pro->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => CarbonImmutable::createFromTimestamp(static::EVENT_AT + 30),
            'plan_current_period_end' => CarbonImmutable::createFromTimestamp($storePeriodEnd),
            'plan_renews' => true,
        ])->save();

        $this->postSignedWebhook(
            $this->invoiceEvent('evt_carry_invoice', $team, created: static::EVENT_AT + 60),
        )->assertOk();

        $team->refresh();

        // The cross-rail upgrade really does land, so the rail on record changed.
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame(BillingProvider::Stripe->value, $team->plan_provider);

        // ...and the store's period did not follow it across.
        $this->assertNull($team->plan_current_period_end);
        $this->assertNull($team->plan_renews);
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
            $this->subscriptionEvent(
                'evt_gap',
                'customer.subscription.updated',
                $team,
                'price_unmapped',
                'active',
                created: static::EVENT_AT + 60,
            ),
        )->assertOk();

        $team->refresh();
        $this->assertSame(Plan::Pro, $team->plan);
        $this->assertSame('active', $team->plan_status);

        // The provenance still points at the seed event: the skip means NO write
        // happened, which a plan assertion alone cannot distinguish from a write
        // that happened to land on the same tier.
        $this->assertSame(static::EVENT_AT, $team->plan_source_event_at->getTimestamp());

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
     * `$created` is the event's own timestamp and the only thing that orders one
     * delivery against another, so it is a parameter rather than a constant: a
     * scenario delivering two events to one team has to make the second strictly
     * newer or the monotonic rule drops it.
     *
     * `$currentPeriodEnd` and `$cancelAtPeriodEnd` are OMITTED from the payload
     * when null rather than written as null. Stripe's absent key is what makes
     * `plan_renews` NULL mean "the rail has not said", which is a different
     * claim from false, so the absent-key path has to be reachable from here.
     *
     * @return array<string, mixed>
     */
    protected function subscriptionEvent(
        string $eventId,
        string $type,
        Team $team,
        string $priceId,
        string $status,
        int $created = self::EVENT_AT,
        ?int $currentPeriodEnd = null,
        ?bool $cancelAtPeriodEnd = null,
    ): array {
        $object = [
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
        ];

        // Stripe carries the period end on the subscription ITEM in the current
        // API version, which is where Cashier reads it from too.
        if ($currentPeriodEnd !== null) {
            $object['items']['data'][0]['current_period_end'] = $currentPeriodEnd;
        }

        if ($cancelAtPeriodEnd !== null) {
            $object['cancel_at_period_end'] = $cancelAtPeriodEnd;
        }

        return [
            'id' => $eventId,
            'type' => $type,
            'created' => $created,
            'data' => ['object' => $object],
        ];
    }

    /**
     * Build a Stripe invoice.payment_succeeded event payload.
     *
     * An invoice object carries no subscription items, which is why this builder
     * has no period parameters: the handler has no period to read here.
     *
     * @return array<string, mixed>
     */
    protected function invoiceEvent(string $eventId, Team $team, int $created = self::EVENT_AT): array
    {
        return [
            'id' => $eventId,
            'type' => 'invoice.payment_succeeded',
            'created' => $created,
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
