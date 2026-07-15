<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Laravel\Cashier\PaymentMethod;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

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
     * Return the static plan catalog, cheapest tier first.
     *
     * Served verbatim from config/plans.php (the single price + limits source),
     * with no Stripe call and no per-team state, so it is safe on the hot path.
     */
    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => config('plans.tiers'),
        ]);
    }

    /**
     * Report the current team's resource usage against its plan's limits.
     *
     * Every count is team-scoped: monitors by owning team, responders as the
     * team's distinct membership (owner plus attached members), and this
     * month's checks over the team's monitors. The check count rides the
     * (team_id, checked_at) index on the monitor_checks hypertable.
     */
    public function usage(Request $request): JsonResponse
    {
        $team = $this->resolveTeam($request);

        // 1. Read the caps for the entitled tier from the static catalog. A
        //    missing catalog row or a null limit means "unlimited".
        $limits = collect(config('plans.tiers'))
            ->firstWhere('id', $team->entitledPlan()->value)['limits'] ?? [];

        // 2. Count the team's live monitors (soft-deleted rows are excluded by
        //    the model's default scope).
        $monitorsUsed = Monitor::query()
            ->where('team_id', $team->id)
            ->count();

        // 3. Responders are the team's distinct people: the owner plus every
        //    attached member, de-duplicated.
        $respondersUsed = $team->users
            ->pluck('id')
            ->push($team->user_id)
            ->unique()
            ->count();

        // 4. Checks recorded for the team's monitors since the start of this
        //    month, scoped through the denormalized team_id column.
        $checksUsed = MonitorCheck::query()
            ->where('team_id', $team->id)
            ->where('checked_at', '>=', now()->startOfMonth())
            ->count();

        return response()->json([
            'monitors' => [
                'used' => $monitorsUsed,
                'limit' => $limits['monitors'] ?? null,
            ],
            'responders' => [
                'used' => $respondersUsed,
                'limit' => $limits['responders'] ?? null,
            ],
            // Checks are not a plan-capped resource in the catalog, so the
            // limit is null (uncapped); the count is informational usage only.
            'checks_this_month' => [
                'used' => $checksUsed,
                'limit' => null,
            ],
        ]);
    }

    /**
     * Cursor-paginate the current team's Stripe invoices.
     *
     * Cashier's `cursorPaginateInvoices` takes the cursor as its fourth
     * argument, so it is passed by name; the encoded next cursor rides
     * alongside the data for the client's "load more".
     */
    public function invoices(Request $request): AnonymousResourceCollection
    {
        $team = $this->resolveTeam($request);

        $invoices = $team->cursorPaginateInvoices(
            perPage: 24,
            cursor: $request->query('cursor'),
        );

        return InvoiceResource::collection($invoices->items())
            ->additional([
                'next_cursor' => $invoices->nextCursor()?->encode(),
            ]);
    }

    /**
     * Return the current team's default card and renewal date.
     *
     * This is the ONLY Stripe-live billing endpoint and it is kept off the
     * entitlement hot path. On any Stripe failure it soft-fails: the error is
     * logged and every field returns null with a 200, so a Stripe outage
     * degrades this one card instead of 500-ing the whole billing screen.
     */
    public function paymentMethod(Request $request): JsonResponse
    {
        $team = $this->resolveTeam($request);

        try {
            $subscription = $team->subscription('default');

            // renewal_date favours the local trial end (a cheap DB read) and
            // only falls back to the live currentPeriodEnd() Stripe call when
            // there is no trial.
            $renewalDate = $subscription?->trial_ends_at ?? $subscription?->currentPeriodEnd();

            $paymentMethod = $team->defaultPaymentMethod();

            // Only a Cashier PaymentMethod exposes a card; a null default or a
            // legacy Stripe Source yields null card fields.
            $card = $paymentMethod instanceof PaymentMethod
                ? $paymentMethod->asStripePaymentMethod()->card
                : null;

            return response()->json([
                'renewal_date' => $renewalDate?->toIso8601String(),
                'brand' => $card?->brand,
                'last4' => $card?->last4,
                'exp_month' => $card?->exp_month,
                'exp_year' => $card?->exp_year,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Failed to read the team payment method from Stripe.', [
                'team_id' => $team->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'renewal_date' => null,
                'brand' => null,
                'last4' => null,
                'exp_month' => null,
                'exp_year' => null,
            ]);
        }
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
