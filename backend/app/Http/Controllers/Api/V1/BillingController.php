<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Resources\SubscriptionResource;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped billing endpoints: JSON checkout/swap/cancel/portal plus the
 * current entitlement read.
 *
 * Every action resolves the acting user's `currentTeam` relation, never a
 * route-supplied team id, so there is no cross-team billing surface to
 * guard against. Cashier's `Checkout` object is always unwrapped to its
 * `url`/`id` for the JSON API; it is never returned or redirected to
 * directly (see research/01 section 2). Price ids are resolved from the
 * `cashier.plans` config map (`['price_id' => 'plan_value']`), the same
 * map {@see StripeWebhookController} reads to
 * project the entitlement, so the price <-> plan mapping has one source.
 */
class BillingController extends Controller
{
    /**
     * Read the current team's entitlement + subscription.
     */
    public function show(Request $request): SubscriptionResource
    {
        return SubscriptionResource::make($this->resolveTeam($request));
    }

    /**
     * Begin a Stripe Checkout session for a paid plan, unwrapped to a
     * JSON-friendly `{checkout_url, session_id}` shape.
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', Rule::in([Plan::Pro->value, Plan::Business->value])],
            'success_url' => ['required', 'string', 'url'],
            'cancel_url' => ['required', 'string', 'url'],
        ]);

        $team = $this->resolveTeam($request);
        $priceId = $this->resolvePriceId($validated['plan']);

        abort_if($priceId === null, HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'No Stripe price is mapped to this plan.');

        $checkout = $team->checkout([$priceId => 1], [
            'success_url' => $validated['success_url'],
            'cancel_url' => $validated['cancel_url'],
        ]);

        return response()->json([
            'checkout_url' => $checkout->url,
            'session_id' => $checkout->id,
        ]);
    }

    /**
     * Swap the current team's default subscription to a different plan's price.
     */
    public function swap(Request $request): SubscriptionResource
    {
        $validated = $request->validate([
            'plan' => ['required', Rule::in([Plan::Pro->value, Plan::Business->value])],
        ]);

        $team = $this->resolveTeam($request);
        $subscription = $team->subscription('default');

        abort_if($subscription === null, HttpResponse::HTTP_NOT_FOUND, 'No active subscription to swap.');

        $priceId = $this->resolvePriceId($validated['plan']);

        abort_if($priceId === null, HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'No Stripe price is mapped to this plan.');

        $subscription->swap($priceId);

        return SubscriptionResource::make($team);
    }

    /**
     * Cancel the current team's default subscription at the end of the
     * current billing period.
     */
    public function cancel(Request $request): SubscriptionResource
    {
        $team = $this->resolveTeam($request);
        $subscription = $team->subscription('default');

        abort_if($subscription === null, HttpResponse::HTTP_NOT_FOUND, 'No active subscription to cancel.');

        $subscription->cancel();

        return SubscriptionResource::make($team);
    }

    /**
     * Return the Stripe billing portal URL for the current team.
     */
    public function portal(Request $request): JsonResponse
    {
        $team = $this->resolveTeam($request);

        return response()->json([
            'portal_url' => $team->billingPortalUrl($request->query('return_url')),
        ]);
    }

    /**
     * Resolve the acting user's current team, 404-ing when none is set.
     */
    protected function resolveTeam(Request $request): Team
    {
        $team = $request->user()->currentTeam;

        abort_if($team === null, HttpResponse::HTTP_NOT_FOUND, 'No current team.');

        return $team;
    }

    /**
     * Resolve a Stripe price id for the given plan via the config plan map,
     * returning null when the plan has no mapped price.
     */
    protected function resolvePriceId(string $plan): ?string
    {
        $priceId = array_search($plan, config('cashier.plans', []), true);

        return $priceId === false ? null : $priceId;
    }
}
