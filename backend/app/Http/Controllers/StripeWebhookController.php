<?php

namespace App\Http\Controllers;

use App\Actions\Billing\WriteTeamEntitlement;
use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Models\ProcessedWebhookEvent;
use App\Models\Team;
use App\Support\Billing\EntitlementWrite;
use App\Support\Billing\StripeSubscriptionState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Stripe webhook feeder for the Team entitlement column.
 *
 * Cashier keeps the local `subscriptions` table in sync with Stripe; this
 * subclass layers two guarantees on top of that:
 *
 *  1. Idempotency: the dedup record ({@see ProcessedWebhookEvent::recordIfNew()})
 *     and the event's side effect run inside one transaction, so a re-delivered
 *     event (Horizon retry, Stripe re-send) is a total no-op while a mid-handler
 *     failure rolls the dedup row back and lets Stripe's retry reprocess it.
 *  2. Entitlement projection: subscription and paid-invoice events claim the
 *     authoritative `teams.plan` column through {@see WriteTeamEntitlement},
 *     making Stripe ONE feeder of the single entitlement read
 *     ({@see Team::entitledPlan()}) rather than the truth itself.
 *
 * The price->plan tier map lives under the `cashier.plans` config key
 * (`['price_id' => 'plan_value']`).
 *
 * ## What this feeder decides, and what it does not
 *
 * Which tier a price grants, and whether a status grants at all, are decided
 * here: they are questions about Stripe's vocabulary and only a Stripe feeder
 * can answer them. WHETHER the claim lands is not, because that answer depends
 * on what another rail has already granted. So every write goes through the
 * action as an {@see EntitlementWrite} carrying its provenance, and the action's
 * two ordering rules decide.
 *
 * Stripe's status word is mapped onto the neutral {@see PlanStatus} vocabulary
 * and the raw word travels alongside it in `plan_provider_status`, which gates
 * nothing. An unknown word therefore cannot entitle by accident and cannot be
 * lost either.
 *
 * ## The projection never calls the Stripe API
 *
 * Every field the entitlement projection writes comes out of the payload it was
 * handed. That is a hard constraint rather than a preference: Cashier's
 * `Subscription::currentPeriodEnd()` and `billingPortalUrl()` are both live
 * Stripe round-trips (the first one PER subscription item, via
 * `SubscriptionItem::asStripeSubscriptionItem()`), and this runs inside a
 * `DB::transaction` that Stripe expects an answer from quickly. Reading either
 * one here would put N synchronous network calls inside that transaction and
 * let a Stripe outage break the GRANTING path for the sake of a provenance
 * column.
 *
 * The claim is scoped to the projection on purpose, because it is NOT true of
 * the request as a whole: Cashier's own `handleCustomerSubscriptionUpdated()`
 * answers a truthy `cancel_at_period_end` with `$subscription->currentPeriodEnd()`
 * (`vendor/laravel/cashier/src/Http/Controllers/WebhookController.php:174-177`),
 * so a cancellation event already dials Stripe from inside this transaction,
 * upstream of anything here. Do not read the heading as an invariant for the
 * whole route, and do not build a timeout or transaction-boundary assumption on
 * top of it.
 */
class StripeWebhookController extends CashierWebhookController
{
    /**
     * @param  WriteTeamEntitlement  $writeTeamEntitlement  the only code path
     *                                                      allowed to write the
     *                                                      entitlement columns
     */
    public function __construct(protected WriteTeamEntitlement $writeTeamEntitlement)
    {
        // Cashier's own constructor attaches VerifyWebhookSignature when a
        // webhook secret is configured; skipping it would unsign this route.
        parent::__construct();
    }

    /**
     * Handle customer subscription created.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        return $this->processOnce($payload, function (array $payload): Response {
            $response = parent::handleCustomerSubscriptionCreated($payload);
            $this->syncEntitlementFromSubscription($payload['data']['object'], $this->eventAt($payload));

            return $response;
        });
    }

    /**
     * Handle customer subscription updated.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        return $this->processOnce($payload, function (array $payload): Response {
            // Parent returns null on the incomplete_expired branch (it deletes
            // the subscription); the projection still downgrades to free there.
            $response = parent::handleCustomerSubscriptionUpdated($payload);
            $this->syncEntitlementFromSubscription($payload['data']['object'], $this->eventAt($payload));

            return $response ?? $this->successMethod();
        });
    }

    /**
     * Handle the cancellation of a customer subscription.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        return $this->processOnce($payload, function (array $payload): Response {
            $response = parent::handleCustomerSubscriptionDeleted($payload);
            $this->downgradeEntitlement($payload['data']['object'], $this->eventAt($payload));

            return $response;
        });
    }

    /**
     * Handle invoice payment succeeded.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoicePaymentSucceeded(array $payload): Response
    {
        return $this->processOnce($payload, function (array $payload): Response {
            $response = parent::handleInvoicePaymentSucceeded($payload);
            $this->reaffirmEntitlementFromInvoice($payload['data']['object'], $this->eventAt($payload));

            return $response;
        });
    }

    /**
     * Insert-then-handle guard around a single event's side effects.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function processOnce(array $payload, Closure $handler): Response
    {
        // The dedup insert and the handler share one transaction: a mid-handler
        // failure rolls the dedup row back with the side effect, so Stripe's
        // retry reprocesses the event instead of hitting a permanent no-op that
        // would leave a canceled team on its paid tier for free. The unique
        // `event_id` index still serializes concurrent deliveries: a losing
        // racer blocks on the winner's row lock and only sees the violation
        // once the winner commits.
        return DB::transaction(function () use ($payload, $handler): Response {
            // 1. Claim the event id first; a losing re-delivery skips every side
            //    effect (parent sync AND entitlement projection) and returns 200.
            if (! ProcessedWebhookEvent::recordIfNew($payload['id'], $payload['type'])) {
                return $this->successMethod();
            }

            // 2. First delivery: run Cashier's sync, then project the entitlement.
            return $handler($payload);
        });
    }

    /**
     * Project a subscription object onto the team entitlement column.
     *
     * @param  array<string, mixed>  $object
     */
    protected function syncEntitlementFromSubscription(array $object, CarbonInterface $eventAt): void
    {
        $team = $this->resolveTeam($object['customer'] ?? null);

        if (! $team) {
            return;
        }

        $status = $object['status'] ?? 'incomplete';
        $priceId = $object['items']['data'][0]['price']['id'] ?? null;

        // 1. A genuinely non-granting status (canceled/unpaid/incomplete) really
        //    does revoke the entitlement to the free tier. The check stays on
        //    Stripe's own word rather than on the mapped status: it is the list
        //    that has always decided this, and re-deriving it from the neutral
        //    vocabulary would be a second definition of "granting" for the same
        //    outcome, free to disagree with the first one.
        if (! StripeSubscriptionState::grants($status)) {
            $this->claim($this->subscriptionClaim($team, Plan::Free, $status, $object, $eventAt));

            return;
        }

        // 2. A granting status whose price is unmapped is a config gap, not a
        //    downgrade: skip the write so the config gap never revokes a paid
        //    tier, and surface the missing price->plan mapping for operators.
        $plan = StripeSubscriptionState::planForPrice($priceId);

        if (! $plan instanceof Plan) {
            $this->warnUnmappedPrice($priceId, $team);

            return;
        }

        $this->claim($this->subscriptionClaim($team, $plan, $status, $object, $eventAt));
    }

    /**
     * A paid subscription invoice re-affirms the active entitlement tier read
     * from the team's synced Cashier subscription price.
     *
     * @param  array<string, mixed>  $object
     */
    protected function reaffirmEntitlementFromInvoice(array $object, CarbonInterface $eventAt): void
    {
        $team = $this->resolveTeam($object['customer'] ?? null);

        if (! $team) {
            return;
        }

        $subscription = $team->subscription('default');

        if (! $subscription) {
            return;
        }

        // A paid invoice must never downgrade the payer: an unmapped price is a
        // config gap, so leave the entitlement untouched and warn instead.
        $plan = StripeSubscriptionState::planForPrice($subscription->stripe_price);

        if (! $plan instanceof Plan) {
            $this->warnUnmappedPrice($subscription->stripe_price, $team);

            return;
        }

        // Carrying the stored period forward is only meaningful while Stripe is
        // the rail on record. A web-to-store migration leaves a team holding a
        // store grant AND an old Cashier row at once, and a cross-rail claim
        // from here would then re-stamp the STORE's period under `stripe`.
        $stripeIsOnRecord = BillingProvider::fromWire($team->plan_provider) === BillingProvider::Stripe;

        $this->claim(new EntitlementWrite(
            team: $team,
            plan: $plan,
            // A paid invoice says exactly one thing about the lifecycle: the
            // money arrived. Stripe reports the status itself on a subscription
            // event, which is where it is read from.
            status: PlanStatus::Active,
            provider: BillingProvider::Stripe,
            eventAt: $eventAt,
            // A PROJECTION, and the only one this controller makes: the tier
            // above comes from the local Cashier row's `stripe_price`, which
            // Cashier may not have resynced for the change this very invoice
            // paid for. It may refresh the record; it may not decide that Stripe
            // is the rail billing this team.
            authoritative: false,
            providerStatus: 'active',
            productId: $subscription->stripe_price,
            // An invoice object carries no subscription items, so this path has
            // no period of its own to read and the local Cashier row has no
            // period column to fall back on. On the Stripe rail the stored
            // values are carried forward, which is what leaves the two columns
            // as they were: the action writes all ten columns on every apply,
            // so passing nothing would blank a period a subscription event had
            // already established. Off it, null is the honest answer, because
            // this feeder does not know Stripe's period and reading Cashier's
            // accessor for it would be a live Stripe call (see the class
            // docblock).
            currentPeriodEnd: $stripeIsOnRecord ? $team->plan_current_period_end : null,
            renews: $stripeIsOnRecord ? $team->plan_renews : null,
        ));
    }

    /**
     * A deleted subscription revokes the entitlement back to the free tier.
     *
     * @param  array<string, mixed>  $object
     */
    protected function downgradeEntitlement(array $object, CarbonInterface $eventAt): void
    {
        $team = $this->resolveTeam($object['customer'] ?? null);

        if (! $team) {
            return;
        }

        $this->claim(new EntitlementWrite(
            team: $team,
            plan: Plan::Free,
            status: PlanStatus::Canceled,
            provider: BillingProvider::Stripe,
            eventAt: $eventAt,
            // Stripe telling us directly, on its own event.
            authoritative: true,
            // Stripe does not put a status on the deletion itself; the deletion
            // IS the status, and `canceled` is the word Stripe uses for it.
            providerStatus: 'canceled',
            productId: $object['items']['data'][0]['price']['id'] ?? null,
            // Deliberately not carried forward: a deleted subscription has no
            // period left to run and nothing left to renew, so both fields are
            // now unknown rather than merely unread.
        ));
    }

    /**
     * The claim a subscription object makes about a team's entitlement.
     *
     * `$plan` is a parameter rather than derived here because the two callers
     * reach it differently: a non-granting status revokes to free whatever the
     * price says, and a granting one takes the tier its price maps to. `$status`
     * is passed in for a stricter reason: the caller has already defaulted an
     * absent one, and defaulting it a second time here would be two definitions
     * of the same word, free to disagree.
     *
     * @param  array<string, mixed>  $object
     */
    protected function subscriptionClaim(
        Team $team,
        Plan $plan,
        string $status,
        array $object,
        CarbonInterface $eventAt,
    ): EntitlementWrite {
        return new EntitlementWrite(
            team: $team,
            plan: $plan,
            status: StripeSubscriptionState::planStatusFor($status),
            provider: BillingProvider::Stripe,
            eventAt: $eventAt,
            // Every field here is read out of the event payload Stripe signed,
            // so this is Stripe speaking rather than us remembering.
            authoritative: true,
            providerStatus: $status,
            productId: $object['items']['data'][0]['price']['id'] ?? null,
            currentPeriodEnd: $this->periodEndFromPayload($object),
            renews: $this->renewsFromPayload($object),
            // Two columns the Stripe rail has no source for. Cashier's
            // `onGracePeriod()` means "cancelled but still entitled", which is
            // already said by `renews = false` plus a future period end, and
            // Stripe's real dunning window is a Stripe Billing setting that
            // appears nowhere on a webhook payload. `plan_manage_url` holds
            // only durable values; the Stripe rail mints a short-lived portal
            // session per request through `GET /billing/portal` instead.
        );
    }

    /**
     * Hand one claim to the single entitlement write path.
     */
    protected function claim(EntitlementWrite $write): void
    {
        ($this->writeTeamEntitlement)($write);
    }

    /**
     * The event's OWN timestamp, which is what orders one delivery against
     * another.
     *
     * Never `now()`. Receipt time only ever increases, so a feeder stamping it
     * would read every write as the freshest truth on record and disarm the
     * monotonic rule while satisfying every type check, which is the failure
     * {@see EntitlementWrite::$eventAt} warns about. Stripe puts `created` on
     * every Event object, so the fallback is unreachable in practice; it is the
     * epoch rather than `now()` because a degenerate timestamp has to LOSE to a
     * real one, not beat it.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function eventAt(array $payload): CarbonInterface
    {
        return CarbonImmutable::createFromTimestamp((int) ($payload['created'] ?? 0));
    }

    /**
     * When the paid period ends, read out of the payload the handler already
     * holds.
     *
     * The current Stripe API version carries this on the subscription ITEM,
     * which is the same array the price id is read from a few lines up and the
     * place Cashier reads it from too; older versions carry it at the
     * subscription level, hence the fallback. Absent on both, the period is
     * unknown and stays NULL.
     *
     * Named for its SOURCE rather than for the field, so that it cannot be
     * mistaken for Cashier's `Subscription::currentPeriodEnd()`. That one is a
     * live Stripe retrieve per subscription item and is exactly what must never
     * be called from this handler.
     *
     * @param  array<string, mixed>  $object
     */
    protected function periodEndFromPayload(array $object): ?CarbonInterface
    {
        $timestamp = $object['items']['data'][0]['current_period_end']
            ?? $object['current_period_end']
            ?? null;

        return $timestamp === null ? null : CarbonImmutable::createFromTimestamp((int) $timestamp);
    }

    /**
     * Whether the subscription rolls over, which is Stripe's
     * `cancel_at_period_end` inverted.
     *
     * An ABSENT key leaves the column NULL rather than assuming it renews: null
     * means the rail has not said, which is a different claim from false, and
     * defaulting either way would invent one.
     *
     * @param  array<string, mixed>  $object
     */
    protected function renewsFromPayload(array $object): ?bool
    {
        if (! array_key_exists('cancel_at_period_end', $object)) {
            return null;
        }

        return ! (bool) $object['cancel_at_period_end'];
    }

    /**
     * Resolve the team acting as the Stripe customer.
     */
    protected function resolveTeam(?string $customerId): ?Team
    {
        if (! $customerId) {
            return null;
        }

        $team = $this->getUserByStripeId($customerId);

        return $team instanceof Team ? $team : null;
    }

    /**
     * Surface a granting subscription whose price id has no plan mapping, so a
     * production config gap is observable instead of silently downgrading a
     * paying customer.
     */
    protected function warnUnmappedPrice(?string $priceId, Team $team): void
    {
        Log::warning('Stripe price id is not mapped to a plan; entitlement left untouched.', [
            'price_id' => $priceId,
            'team_id' => $team->id,
        ]);
    }
}
