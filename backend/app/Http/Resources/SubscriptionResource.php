<?php

namespace App\Http\Resources;

use App\Actions\Billing\WriteTeamEntitlement;
use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Support\Billing\StripeSubscriptionState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Cashier\Concerns\ManagesCustomer;

/**
 * JSON shape for the current team's billing entitlement, in rail-neutral words.
 *
 * Every field is read from the `teams` row: `plan`/`plan_status` are the single
 * entitlement truth ({@see Team::entitledPlan()}) and the eight `plan_*`
 * provenance columns are written by whichever rail's event last claimed the
 * team ({@see WriteTeamEntitlement}). Naming a rail on the wire would force
 * every client to learn one rail's dialect and then relearn it when a second
 * rail arrives, so the rail's own words survive only in `provider_status`,
 * which is opaque debug text and never a gate.
 *
 * FIVE of the thirteen fields are non-null guaranteed, and a decoder may rely on
 * it: `plan`, `plan_status`, `subscribed`, `provider`, `manage_via`. The other
 * eight are nullable, four of them on the Stripe rail BY DESIGN rather than by
 * accident: `manage_url` and `grace_period_ends_at` have no Stripe source at
 * all, and `provider_status` and `product_id` stay null until a rail writes
 * them.
 *
 * NOTHING here may dial a payment rail. This resource is on the billing screen's
 * hot path, and both of Cashier's tempting accessors are live network calls:
 * `Subscription::currentPeriodEnd()` retrieves per subscription item, and
 * `billingPortalUrl()` mints a portal session. The provenance columns exist so
 * that the period, the product and the management destination are local reads.
 * The one Cashier read that stays is `subscriptions.trial_ends_at`, a plain
 * column, which is why `trial_ends_at` is Stripe-only by construction.
 *
 * @property Team $resource
 */
class SubscriptionResource extends JsonResource
{
    /**
     * Transform the team's entitlement into its wire shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $team = $this->resource;
        $plan = $team->entitledPlan();
        $status = PlanStatus::fromWire($team->plan_status);
        $provider = BillingProvider::fromWire($team->plan_provider);

        return [
            'plan' => $plan->value,
            'plan_status' => $status->value,
            'subscribed' => $this->subscribed($plan, $status),
            // Nullable on purpose: null means the rail has not said whether this
            // subscription rolls over, which is not the claim `false` makes.
            'renews' => $team->plan_renews,
            // Derived from the price the subscription sits on rather than stored
            // beside it, so it cannot drift from the price that is billing them.
            // Null on three honest occasions and none of them defaulted: no rail, a
            // price whose cycle `cashier.plans` never declared, and a STORE
            // subscription, whose product id this Stripe catalogue cannot name.
            'cycle' => StripeSubscriptionState::cycleForPrice($team->plan_product_id)?->value,
            'provider' => $provider->value,
            // Debug and support text only. It carries a rail's own word,
            // including words the neutral vocabulary has none for, so it must
            // never reach a gate or a computed field.
            'provider_status' => $team->plan_provider_status,
            'product_id' => $team->plan_product_id,
            'manage_via' => $this->manageVia($provider, $team),
            'manage_url' => $this->manageUrl($provider, $team),
            // When the paid period ends, whether or not it renews. Deliberately
            // not Cashier's `ends_at`, which answers a different question (the
            // date a cancellation takes effect) and has no store-rail meaning.
            'current_period_end' => $team->plan_current_period_end?->toIso8601String(),
            'trial_ends_at' => $team->subscription('default')?->trial_ends_at?->toIso8601String(),
            'grace_period_ends_at' => $team->plan_grace_period_ends_at?->toIso8601String(),
            // Metered AI monitor setups left, or null on a tier that entitles AI
            // analysis outright, so the client can show the allowance before the
            // user spends the first one.
            'ai_analysis_trials_remaining' => (new PlanGate)->aiAnalysisTrialsRemaining($team),
        ];
    }

    /**
     * Whether the team currently holds a paid plan.
     *
     * Derived from the entitlement columns rather than from Cashier's
     * `active()`, whose vocabulary is Stripe's and therefore answers nothing
     * about a store-sold plan. Both halves are load-bearing: the tier says the
     * team was sold something above Free, and {@see PlanStatus::grants()} says
     * that plan is still owed to them, which keeps a customer with a failed
     * charge subscribed while their rail retries.
     *
     * A revoked team now stores NULL in `teams.plan` rather than `'free'`, and
     * `$plan` arrives through {@see Team::entitledPlan()}, which reads that NULL
     * as `Plan::Free`. That collapse is wanted HERE: this is a display reader,
     * and both rows mean the same thing to a client, which is why `plan` stays
     * one of the five non-null wire fields. Only the arbitration reader needs the
     * two apart.
     */
    protected function subscribed(Plan $plan, PlanStatus $status): bool
    {
        return $plan !== Plan::Free && $status->grants();
    }

    /**
     * Where the customer manages this subscription, as one of `none`, `portal`,
     * `app_store` or `play_store`.
     *
     * Computed server-side so no client has to learn the rail-to-surface
     * mapping, and computed from the RAIL rather than from the request's
     * platform: the two are independent axes, and a subscription bought on an
     * iPhone is still managed in the App Store when the customer opens the web
     * app. It is a function of the rail plus one existence check, never of
     * whether the team is currently subscribed, because a cancelled customer
     * still needs to reach invoices and remove a card.
     *
     * The `hasStripeId()` half is correctness, not padding: `billingPortalUrl()`
     * calls `assertCustomerExists()` as its first line
     * ({@see ManagesCustomer::billingPortalUrl()}), so naming `portal` for a
     * customer-less team would be the wire pointing the client at an endpoint
     * that cannot answer.
     *
     * `portal` is a surface rather than a URL because a Stripe portal session is
     * short-lived, single-use and carries a baked-in `return_url`; the client
     * calls `GET /billing/portal` for it. There is deliberately no
     * `contact_sales` value: {@see Plan} has only Free, Pro and Business, so
     * `teams.plan` can never hold `enterprise`. That tier exists as a catalogue
     * row for the plan grid, and a grid CTA is not an entitlement-management
     * state.
     *
     * The `match` has no `default` arm on purpose: a fifth rail has to be
     * decided here rather than inheriting a quiet `none`.
     */
    protected function manageVia(BillingProvider $provider, Team $team): string
    {
        return match ($provider) {
            BillingProvider::AppStore => 'app_store',
            BillingProvider::PlayStore => 'play_store',
            BillingProvider::Stripe => $team->hasStripeId() ? 'portal' : 'none',
            BillingProvider::None, BillingProvider::Manual => 'none',
        };
    }

    /**
     * The destination that pairs with a store `manage_via`, else null.
     *
     * Only a store rail has a durable one, and it is passed through from
     * RevenueCat's own `subscriber.management_url` rather than hardcoded, so a
     * store moving its subscriptions page does not need an app release. Gated on
     * the rail rather than merely read from the column: a stale or mistaken
     * value on a non-store row must not reach a client that would open it.
     */
    protected function manageUrl(BillingProvider $provider, Team $team): ?string
    {
        return $provider->isStore() ? $team->plan_manage_url : null;
    }
}
