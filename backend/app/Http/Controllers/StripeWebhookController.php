<?php

namespace App\Http\Controllers;

use App\Enums\Plan;
use App\Models\ProcessedWebhookEvent;
use App\Models\Team;
use Closure;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Stripe webhook feeder for the Team entitlement column.
 *
 * Cashier keeps the local `subscriptions` table in sync with Stripe; this
 * subclass layers two guarantees on top of that:
 *
 *  1. Idempotency: every handled event is recorded through
 *     {@see ProcessedWebhookEvent::recordIfNew()} BEFORE its side effect runs,
 *     so a re-delivered event (Horizon retry, Stripe re-send) is a total no-op.
 *  2. Entitlement projection: subscription and paid-invoice events write the
 *     authoritative `teams.plan` / `teams.plan_status` column, making Stripe the
 *     source that feeds the single entitlement read ({@see Team::entitledPlan()}).
 *
 * The price->plan tier map lives under the `cashier.plans` config key
 * (`['price_id' => 'plan_value']`); the plan status is taken verbatim from the
 * Stripe subscription status.
 */
class StripeWebhookController extends CashierWebhookController
{
    /**
     * The Stripe subscription statuses that grant a paid entitlement.
     *
     * @var array<int, string>
     */
    protected array $grantingStatuses = [
        'active',
        'trialing',
        'past_due',
    ];

    /**
     * Handle customer subscription created.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        return $this->processOnce($payload, function (array $payload): Response {
            $response = parent::handleCustomerSubscriptionCreated($payload);
            $this->syncEntitlementFromSubscription($payload['data']['object']);

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
            $this->syncEntitlementFromSubscription($payload['data']['object']);

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
            $this->downgradeEntitlement($payload['data']['object']['customer'] ?? null);

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
            $this->reaffirmEntitlementFromInvoice($payload['data']['object']);

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
        // 1. Claim the event id first; a losing re-delivery skips every side
        //    effect (parent sync AND entitlement projection) and returns 200.
        if (! ProcessedWebhookEvent::recordIfNew($payload['id'], $payload['type'])) {
            return $this->successMethod();
        }

        // 2. First delivery: run Cashier's sync, then project the entitlement.
        return $handler($payload);
    }

    /**
     * Project a subscription object onto the team entitlement column.
     *
     * @param  array<string, mixed>  $object
     */
    protected function syncEntitlementFromSubscription(array $object): void
    {
        $team = $this->resolveTeam($object['customer'] ?? null);

        if (! $team) {
            return;
        }

        $status = $object['status'] ?? 'incomplete';
        $priceId = $object['items']['data'][0]['price']['id'] ?? null;

        $plan = in_array($status, $this->grantingStatuses, true)
            ? $this->resolvePlanFromPrice($priceId)
            : Plan::Free;

        $this->writeEntitlement($team, $plan, $status);
    }

    /**
     * A paid subscription invoice re-affirms the active entitlement tier read
     * from the team's synced Cashier subscription price.
     *
     * @param  array<string, mixed>  $object
     */
    protected function reaffirmEntitlementFromInvoice(array $object): void
    {
        $team = $this->resolveTeam($object['customer'] ?? null);

        if (! $team) {
            return;
        }

        $subscription = $team->subscription('default');

        if (! $subscription) {
            return;
        }

        $this->writeEntitlement($team, $this->resolvePlanFromPrice($subscription->stripe_price), 'active');
    }

    /**
     * A deleted subscription revokes the entitlement back to the free tier.
     */
    protected function downgradeEntitlement(?string $customerId): void
    {
        $team = $this->resolveTeam($customerId);

        if (! $team) {
            return;
        }

        $this->writeEntitlement($team, Plan::Free, 'canceled');
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
     * Map a Stripe price id to its entitlement tier via the config plan map,
     * defaulting to the free tier when the price is absent or unmapped.
     */
    protected function resolvePlanFromPrice(?string $priceId): Plan
    {
        $map = (array) config('cashier.plans', []);

        return Plan::tryFrom((string) ($map[$priceId] ?? '')) ?? Plan::Free;
    }

    /**
     * Persist the entitlement column, the single source of truth read.
     */
    protected function writeEntitlement(Team $team, Plan $plan, string $status): void
    {
        $team->forceFill([
            'plan' => $plan->value,
            'plan_status' => $status,
        ])->save();
    }
}
