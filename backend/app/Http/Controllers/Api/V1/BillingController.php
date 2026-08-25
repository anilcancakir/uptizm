<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\StoreSubscriptionGuardedDeleteTeam;
use App\Enums\BillingCycle;
use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Exceptions\PlanUpgradeRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Policies\BillingPolicy;
use App\Support\Billing\StripeSubscriptionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Laravel\Cashier\PaymentMethod;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeObject;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped billing endpoints: JSON checkout/swap/cancel/portal plus the
 * current entitlement read.
 *
 * Every action resolves the acting user's `currentTeam` relation, never a
 * route-supplied team id. Cashier's `Checkout` object is always unwrapped to
 * its `url`/`id` for the JSON API; it is never returned or redirected to
 * directly (see research/01 section 2). Price ids are resolved from the
 * `cashier.plans` config map (`['price_id' => 'plan_value']`), the same
 * map {@see StripeWebhookController} reads to
 * project the entitlement, so the price <-> plan mapping has one source.
 *
 * FOUR DISTINCT REFUSALS, and telling them apart is the point
 *
 * The four write actions are the OWNER's ({@see BillingPolicy}); the five reads
 * stay open to any member. A wrong caller or a wrong rail therefore has four
 * different answers, and each one drives a different next step in the client:
 *
 * - 404, the team is not there. Either no current team at all, or a
 *   `current_team_id` pointing at a team the caller no longer belongs to. It is
 *   masked as absence rather than refused as forbidden, per the house rule the
 *   sibling controllers follow ({@see StatusPageController::authorizeTeam()}):
 *   a 403 would confirm the team exists.
 * - 403, the caller is a member and not the owner. Raised BEFORE anything
 *   about the subscription is read, so a refused caller learns nothing about
 *   whether one exists.
 * - 409 + {@see self::REASON_MANAGED_BY_STORE}, the subscription exists but is
 *   on a rail we cannot act on. This used to be the 404 below, which claimed
 *   there was nothing to cancel while a store was still charging the customer
 *   every month.
 * - 404, there is genuinely no subscription. Kept, and it is a different fact
 *   from the 409 above rather than a milder version of it.
 */
class BillingController extends Controller
{
    /**
     * The subscription is real but a store sold it, so the store manages it.
     *
     * A machine-readable reason rather than only a sentence, for the same
     * reason {@see PlanUpgradeRequiredException} carries `upgrade.required_plan`:
     * the client has to render "manage this in the App Store" rather than a
     * dead-end toast, and parsing English to decide that is not a contract.
     * The rail itself travels beside it as `billing.provider`, so the client
     * names the right store without this constant having to multiply per rail.
     */
    public const REASON_MANAGED_BY_STORE = 'managed_by_store';

    /**
     * There is no billing account to manage: the team has never been charged,
     * so no Stripe customer exists behind it.
     *
     * DISTINCT from the reason above, because it is a distinct fact and leads
     * somewhere else entirely. "Manage this where you bought it" and "there is
     * nothing to manage yet, start a subscription" are opposite instructions,
     * and a single shared code would leave the client guessing which it got.
     */
    public const REASON_NO_BILLING_ACCOUNT = 'no_billing_account';

    /**
     * This rail is already billing the team, so a checkout would open a SECOND
     * subscription beside the first.
     *
     * A third reason rather than reusing either above, because it leads
     * somewhere neither does: the customer is not being sent elsewhere and not
     * being told there is nothing to manage, they are being told to CHANGE what
     * they already have. The client's next step is `swap`.
     */
    public const REASON_SUBSCRIPTION_EXISTS = 'subscription_exists';

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
        // Authorized before the body is even read: an unauthorized caller's
        // input is not worth validating, and a 422 ahead of the 403 would tell
        // them their request shape was the only thing wrong with it.
        $team = $this->resolveTeamForBillingChange($request);

        // A store already charging this team must not be able to acquire a
        // second, parallel Stripe subscription. WriteTeamEntitlement warns when
        // two rails claim one customer, but a warning arrives after the money
        // has moved; this refuses at the point of sale. The client hides the
        // CTA, and a client gate is an affordance rather than the enforcement.
        $this->guardStoreOwnedSubscription($team);

        // A team this rail is ALREADY billing must not open a second
        // subscription beside the first. `newSubscription()` happily creates
        // one, so without this a customer who cancels and buys again holds two
        // live Stripe subscriptions and pays both. Measured end to end against a
        // live Stripe test account, and the double charge is not the worst of
        // it: Cashier stores both under `type = 'default'`, so
        // `subscription('default')` becomes ambiguous for `swap`, `cancel` and
        // the period read, and the eventual deletion of the older one revoked
        // the entitlement the newer one was paying for.
        //
        // A CANCELLED subscription counts as live while it still grants, which
        // is the case this guard exists for: it is when a customer is most
        // likely to buy again and the one moment the store guard above says
        // nothing about. `swap` stays open to them and un-cancels as a side
        // effect, so nothing is unreachable behind this refusal.
        $this->guardExistingCardSubscription($team);

        $validated = $request->validate([
            'plan' => ['required', Rule::in([Plan::Pro->value, Plan::Business->value])],
            // The cycle decides WHICH of the tier's prices is charged, so it is
            // required rather than defaulted. A default is the whole defect this
            // field closes: a client showing an annual figure and a server
            // picking the monthly price is how a customer gets billed an amount
            // nothing on screen displayed.
            'cycle' => ['required', Rule::enum(BillingCycle::class)],
            'success_url' => ['required', 'string', 'url'],
            'cancel_url' => ['required', 'string', 'url'],
        ]);

        $priceId = $this->resolvePriceId($validated['plan'], $validated['cycle']);

        abort_if($priceId === null, HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'No Stripe price is mapped to this plan.');

        // Through the subscription builder, NOT `Billable::checkout()`. That one
        // routes to `Checkout::create`, which defaults to `mode: payment`
        // (vendor/laravel/cashier/src/Checkout.php:62), and Stripe rejects a
        // recurring price in payment mode outright: "You specified `payment` mode
        // but passed a recurring price." Every price this maps to is recurring,
        // so that call could never open a session; the builder is what asks for
        // `mode: subscription`. The subscription name matches `swap` and `cancel`,
        // which both operate on `subscription('default')`.
        $checkout = $team->newSubscription(StripeSubscriptionState::SUBSCRIPTION_TYPE, $priceId)->checkout([
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
        $team = $this->resolveTeamForBillingChange($request);

        $validated = $request->validate([
            'plan' => ['required', Rule::in([Plan::Pro->value, Plan::Business->value])],
            // Required here too, and not for symmetry: moving a customer from
            // monthly to annual on the SAME tier is a real change, and a swap
            // that could not express it would answer 200 while leaving them on
            // the price they were trying to leave.
            'cycle' => ['required', Rule::enum(BillingCycle::class)],
        ]);

        $this->guardStoreOwnedSubscription($team);

        $subscription = $team->subscription(StripeSubscriptionState::SUBSCRIPTION_TYPE);

        abort_if($subscription === null, HttpResponse::HTTP_NOT_FOUND, 'No active subscription to swap.');

        $priceId = $this->resolvePriceId($validated['plan'], $validated['cycle']);

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
        $team = $this->resolveTeamForBillingChange($request);

        $this->guardStoreOwnedSubscription($team);

        $subscription = $team->subscription(StripeSubscriptionState::SUBSCRIPTION_TYPE);

        // Reached only on a rail we control, so a missing row really does mean
        // there is nothing to cancel. The store case above would otherwise land
        // here and answer "no subscription" to a customer who has one.
        abort_if($subscription === null, HttpResponse::HTTP_NOT_FOUND, 'No active subscription to cancel.');

        $subscription->cancel();

        return SubscriptionResource::make($team);
    }

    /**
     * Return the Stripe billing portal URL for the current team.
     *
     * The guard is not defensive, it is the ordinary path. Cashier's
     * `billingPortalUrl()` opens with `assertCustomerExists()`, which throws
     * `InvalidCustomer` the moment `hasStripeId()` is false, so every team that
     * has never been charged (which is every team on the free tier, i.e. most
     * of them) got a 500 out of this endpoint. The one test covering it mocks
     * the Cashier call, so `assertCustomerExists()` was never reached in the
     * suite and the whole customer-less path was unvisited code.
     */
    public function portal(Request $request): JsonResponse
    {
        $team = $this->resolveTeamForBillingChange($request);

        // Before the customer check, not after: a store-billed team keeps
        // whatever `stripe_id` an earlier web subscription left behind, so
        // `hasStripeId()` is true and the portal would open onto a Stripe
        // subscription that is not the one charging them. The rail is the more
        // specific and more actionable fact of the two.
        $this->guardStoreOwnedSubscription($team);

        if (! $team->hasStripeId()) {
            $this->abortWithBillingConflict(
                self::REASON_NO_BILLING_ACCOUNT,
                BillingProvider::fromWire($team->plan_provider),
                'This team has no billing account to manage yet.',
            );
        }

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
     * Report the caller's OTHER team that a store account is already funding, so
     * the client can refuse a store purchase by NAME instead of transferring one.
     *
     * The structural fact behind it: the two store SKUs share a subscription
     * group so that upgrade and downgrade work at all, and a store account holds
     * at most one active subscription per group. So a second purchase from the
     * same account does not open a second subscription, it MOVES the one that
     * exists, and the team that had it silently stops being funded. The client
     * hides its purchase CTA on this answer; the entitlement itself stays honest
     * either way, because the rail's TRANSFER handling revokes the source and
     * grants the destination. This exists so a customer is not surprised.
     *
     * WHAT IT CANNOT SEE, said plainly: the store ACCOUNT. RevenueCat's App User
     * ID is the team id, so from here every purchase looks like a fresh customer,
     * and the honest proxy is the teams this caller OWNS. That covers one person
     * with two teams and one store account, which is the common case. It does not
     * cover two people sharing one store account, which needs the SDK's
     * `originalAppUserId` and therefore a wider `StoreBillingService`.
     *
     * Teams the caller merely BELONGS TO are excluded, and that is not laxity:
     * only an owner can buy ({@see BillingPolicy}), so a member's team was funded
     * by ITS owner's store account and says nothing about this caller's. Counting
     * it would refuse a legitimate first purchase to anybody who has ever joined
     * a store-billed team.
     *
     * A READ, so it is open to any member like the other five: it reports only on
     * teams the caller already owns, and gating it on ownership would 403 a
     * mount-time fetch the client makes before it knows who is asking.
     */
    public function storeFundedTeam(Request $request): JsonResponse
    {
        $team = $this->resolveTeam($request);

        // Typed `Model` and not `Team`, because the relation is declared over
        // `MagicStarter::teamModel()`: the narrowing belongs in the predicate,
        // which already answers false for anything that is not an uptizm team.
        $funded = $request->user()->ownedTeams
            ->first(fn (Model $other): bool => $other->getKey() !== $team->getKey()
                && StoreSubscriptionGuardedDeleteTeam::storeIsBilling($other));

        return response()->json([
            'store_funded_team' => $funded === null ? null : [
                'id' => $funded->getKey(),
                'name' => $funded->name,
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
     * entitlement hot path. It soft-fails on a Stripe API error only: the
     * error is logged and the four card fields return null with a 200, so a
     * Stripe outage degrades this one card instead of 500-ing the whole
     * billing screen. Any other exception propagates, because folding an
     * unrelated bug into the same 200 would hide it behind an outage that
     * never happened.
     *
     * `available` is the field that lets the two failure shapes be told
     * apart on the wire: `false` means the rail could not be asked (this
     * catch fired), `true` with the four card fields null means the rail
     * answered and there is genuinely no card on file. Before this field
     * the two bodies were byte-identical, so a Stripe outage read to the
     * client as "no card", and the client had to reconstruct the
     * difference from `manage_via` instead of being told directly.
     */
    public function paymentMethod(Request $request): JsonResponse
    {
        $team = $this->resolveTeam($request);

        try {
            $subscription = $team->subscription(StripeSubscriptionState::SUBSCRIPTION_TYPE);

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

            // A hosted checkout does not leave a card where Cashier looks for
            // one: Stripe attaches the payment method to the SUBSCRIPTION it
            // creates and leaves the customer's
            // `invoice_settings.default_payment_method` null, while
            // `defaultPaymentMethod()` reads the customer alone. So every team
            // that bought through the hosted page was told there was no card on
            // file, moments after paying with one.
            //
            // Second rather than first, and the order carries the correctness: a
            // portal update sets the customer's default and Stripe does not
            // rewrite the subscription's, so consulting the subscription first
            // would show the card the customer had just replaced.
            if ($card === null && $subscription !== null) {
                $card = $this->subscriptionCard($subscription);
            }

            return response()->json([
                'available' => true,
                'renewal_date' => $renewalDate?->toIso8601String(),
                'brand' => $card?->brand,
                'last4' => $card?->last4,
                'exp_month' => $card?->exp_month,
                'exp_year' => $card?->exp_year,
            ]);
        } catch (ApiErrorException $exception) {
            // `ApiConnectionException` extends `ApiErrorException`, so a
            // downed network or a bad TLS certificate is caught here too;
            // this is the only outage shape this endpoint soft-fails on.
            Log::warning('Failed to read the team payment method from Stripe.', [
                'team_id' => $team->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'available' => false,
                'renewal_date' => null,
                'brand' => null,
                'last4' => null,
                'exp_month' => null,
                'exp_year' => null,
            ]);
        }
    }

    /**
     * Resolve the acting user's current team, 404-ing when there is none to
     * act on.
     *
     * Two ways there is none, and both answer 404 rather than 403.
     * `current_team_id` is a plain nullable column, so it can be unset, and it
     * also SURVIVES the membership it points at being removed: an ex-member
     * keeps a pointer at a team that is no longer theirs. Masking that as
     * absence is the house rule the sibling controllers follow
     * ({@see StatusPageController::authorizeTeam()}), because a 403 would
     * confirm the team exists and a 200 would hand a stranger the billing
     * state of a team they were removed from.
     *
     * `belongsToTeam()` and not `ownsTeam()` here on purpose: this is the
     * membership question (may this caller see this team at all), which is a
     * different question from whether they may spend its money. That one is
     * {@see self::resolveTeamForBillingChange()}.
     */
    protected function resolveTeam(Request $request): Team
    {
        $user = $request->user();
        $team = $user->currentTeam;

        abort_if($team === null, HttpResponse::HTTP_NOT_FOUND, 'No current team.');
        abort_if(! $user->belongsToTeam($team), HttpResponse::HTTP_NOT_FOUND, 'No current team.');

        return $team;
    }

    /**
     * Resolve the current team for a billing WRITE, 403-ing a member who does
     * not own it.
     *
     * Ordered deliberately: the 404 mask runs first (a team that is not there
     * cannot be refused for the wrong reason), then the ownership gate, and
     * only then does the caller reach anything that reads the subscription. An
     * unauthorized caller must not be able to tell from the response whether
     * the team has a subscription at all.
     *
     * `Gate::forUser()` rather than the ambient `Gate::authorize()`, matching
     * how the starter's team controllers call their own policy: the user is
     * already resolved here and the request's guard is not worth re-resolving.
     */
    protected function resolveTeamForBillingChange(Request $request): Team
    {
        $team = $this->resolveTeam($request);

        Gate::forUser($request->user())->authorize('manageBilling', $team);

        return $team;
    }

    /**
     * Refuse a change to a subscription a store sold and therefore manages.
     *
     * Read through {@see BillingProvider::fromWire()} because `plan_provider`
     * is an UNCAST column by design: an unrecognised value has to land on
     * `None` rather than raise, so a rail this build has never heard of cannot
     * turn a billing screen into an outage. `None` and `Stripe` both fall
     * through to the caller, which is correct: nothing is being managed
     * elsewhere in either case.
     */
    protected function guardStoreOwnedSubscription(Team $team): void
    {
        $provider = BillingProvider::fromWire($team->plan_provider);

        if (! $provider->isStore()) {
            return;
        }

        $this->abortWithBillingConflict(
            self::REASON_MANAGED_BY_STORE,
            $provider,
            'This subscription is managed by the store that sold it and cannot be changed here.',
        );
    }

    /**
     * Refuse a checkout for a team this card rail is already billing.
     *
     * A subscription counts as existing while it still GRANTS, which includes a
     * cancelled one inside its paid period. That is the whole point of the
     * guard: it is when a customer is most likely to buy again, and the only
     * moment the store guard above says nothing about.
     *
     * The status is read from the LOCAL rows rather than from Stripe: this runs
     * on the purchase path, the rows are what Cashier's own webhook handlers
     * keep in step, and a network read here would put a Stripe round trip in
     * front of every checkout.
     *
     * The sentence names the ACTION rather than the obstacle, because it fires
     * most often on a subscription the customer believes they have cancelled:
     * "you already have a subscription" would read as a contradiction to them,
     * and "has not ended yet" is the fact that reconciles it. It avoids "still
     * active", which is false for one of the four states that reach here:
     * `past_due` grants while Stripe retries a declined card.
     *
     * The cost of the local read, stated because it is a REGRESSION in one case
     * rather than a limitation: nothing here heals a row Stripe has moved past,
     * and the reconciler's Stripe half never calls the API either. A dropped
     * `customer.subscription.deleted` therefore leaves `stripe_status = 'active'`
     * forever, and where that customer could once simply buy again they are now
     * refused, with `swap` failing at the API against a subscription that no
     * longer exists. Rare, since Stripe retries deliveries for days, but it
     * needs an operator rather than time: check Stripe before anything else when
     * a customer is stuck behind this refusal.
     */
    protected function guardExistingCardSubscription(Team $team): void
    {
        foreach ($team->subscriptions as $subscription) {
            // Scoped to `default`, the only type any other write here can
            // reach: `swap` and `cancel` both resolve `subscription('default')`,
            // which Cashier filters by type. Refusing on a granting
            // subscription of some other type would close the escape hatch this
            // refusal points at, since `swap` would find nothing and answer 409
            // `no_subscription`, leaving that customer unable to buy at all.
            if ($subscription->type !== StripeSubscriptionState::SUBSCRIPTION_TYPE) {
                continue;
            }

            $status = $subscription->stripe_status;

            if (is_string($status) && StripeSubscriptionState::grants($status)) {
                $this->abortWithBillingConflict(
                    self::REASON_SUBSCRIPTION_EXISTS,
                    BillingProvider::Stripe,
                    'Your subscription has not ended yet, so change your plan instead of buying a second one.',
                );
            }
        }
    }

    /**
     * Refuse a billing write with a machine-readable reason and the rail it
     * concerns.
     *
     * 409 rather than 403 or 422: the caller is authorized and the request is
     * well formed, the STATE of the resource is what conflicts with it. Thrown
     * as an `HttpResponseException` so the JSON body survives intact; an
     * `abort()` with a message would flatten it back to the prose-only shape
     * this exists to replace.
     *
     * @param  string  $reason  One of the `REASON_*` constants on this class.
     * @param  BillingProvider  $provider  The rail the conflict concerns.
     * @param  string  $message  The human sentence, rendered verbatim by the client.
     */
    protected function abortWithBillingConflict(string $reason, BillingProvider $provider, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'billing' => [
                'reason' => $reason,
                'provider' => $provider->value,
            ],
        ], HttpResponse::HTTP_CONFLICT));
    }

    /**
     * The card the subscription itself carries, or null.
     *
     * Expanded in the one retrieval rather than fetched and then resolved: an
     * unexpanded `default_payment_method` is a bare id, and turning that into a
     * card would cost a second round trip on a read that already makes one.
     */
    protected function subscriptionCard(Subscription $subscription): ?StripeObject
    {
        $paymentMethod = $subscription->asStripeSubscription(['default_payment_method'])
            ->default_payment_method;

        if (! $paymentMethod instanceof StripeObject) {
            return null;
        }

        $card = $paymentMethod->card ?? null;

        return $card instanceof StripeObject ? $card : null;
    }

    /**
     * Resolve a Stripe price id for the given plan via the config plan map,
     * returning null when the plan has no mapped price.
     */
    protected function resolvePriceId(string $plan, string $cycle): ?string
    {
        $tier = Plan::tryFrom($plan);
        $billingCycle = BillingCycle::tryFrom($cycle);

        if ($tier === null || $billingCycle === null) {
            return null;
        }

        return StripeSubscriptionState::priceFor($tier, $billingCycle);
    }
}
