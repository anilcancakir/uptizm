<?php

namespace App\Support\Billing;

use App\Enums\BillingCycle;
use App\Enums\Plan;
use App\Enums\PlanStatus;

/**
 * Stripe's own subscription vocabulary, read the same way by every feeder.
 *
 * Two classes used to carry all of this privately: the webhook controller, which
 * reacts to an event, and the hourly reconciler, which re-reads the same
 * subscription when an event was dropped. Their `$grantingStatuses` arrays and
 * their `mapStatus()` matches were byte-identical, and the reconciler's docblock
 * even CITED the controller's array as the list it was kept in step with, which
 * is a claim no code could enforce. Adding `paused` to one of them would have
 * left the other revoking every paused subscription on its next hourly run.
 *
 * The price lookups had already drifted, which is the concrete evidence that the
 * comment was not enough: the controller guarded with `! $priceId` and the
 * reconciler with an explicit null-or-empty check. Those differ on the string
 * `'0'`, which PHP treats as falsy. No Stripe price id looks like that today, so
 * nothing was broken; two copies of one rule disagreeing about an edge case is
 * how they always start.
 *
 * Kept in STRIPE's vocabulary rather than re-derived from
 * {@see PlanStatus::grants()}. That method answers a neutral question for every
 * rail; this class answers what Stripe means, and collapsing the two would make
 * a change for one rail silently change the other.
 */
final class StripeSubscriptionState
{
    /**
     * The Stripe statuses under which a subscription still entitles the team.
     *
     * `past_due` grants on purpose: Stripe is still retrying the card, the
     * customer has not cancelled, and taking their tier away mid-dunning is a
     * support ticket from somebody who is about to pay.
     *
     * @var array<int, string>
     */
    /**
     * The Cashier subscription TYPE this application's Stripe rail acts on.
     *
     * Cashier's named types are a real feature and a team could hold several, so
     * every feeder has to agree on which one it means: the checkout guard
     * refuses on it, the revocation guard holds a tier open for it, the grant
     * path writes from it, and `swap` and `cancel` reach it through
     * `subscription()`. One constant rather than the literal in four files with
     * comments in each arguing that the four must match.
     */
    public const string SUBSCRIPTION_TYPE = 'default';

    public const array GRANTING_STATUSES = [
        'active',
        'trialing',
        'past_due',
    ];

    /**
     * Whether [$status] is one Stripe grants an entitlement under.
     */
    public static function grants(string $status): bool
    {
        return in_array($status, self::GRANTING_STATUSES, true);
    }

    /**
     * Map Stripe's subscription status onto the rail-neutral vocabulary.
     *
     * An explicit table rather than {@see PlanStatus::fromWire()} alone, because
     * three of Stripe's words have no neutral twin: `unpaid` and both
     * `incomplete*` states are lifecycles that ran out without ever being paid,
     * so they land on Expired rather than on a status of their own.
     *
     * Everything unlisted falls THROUGH to `fromWire()`, which lands an
     * unrecognised word on the non-granting default: a status Stripe adds next
     * year must never be able to entitle by accident. Nothing maps onto `active`
     * except the word `active` itself.
     */
    public static function planStatusFor(string $status): PlanStatus
    {
        return match ($status) {
            'active' => PlanStatus::Active,
            'trialing' => PlanStatus::Trialing,
            'past_due' => PlanStatus::PastDue,
            'canceled' => PlanStatus::Canceled,
            'unpaid', 'incomplete', 'incomplete_expired' => PlanStatus::Expired,
            default => PlanStatus::fromWire($status),
        };
    }

    /**
     * The catalogue tier a Stripe price id maps to, or null when none does.
     *
     * Null is a config gap and never a downgrade: a caller that cannot name the
     * tier leaves the entitlement alone and warns, because an unmapped price on
     * a paying subscription means somebody added a price in Stripe and not in
     * `cashier.plans`.
     *
     * The empty check is explicit rather than `! $priceId`, which is the form
     * one of the two copies used: they differ on `'0'`.
     */
    public static function planForPrice(?string $priceId): ?Plan
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        return Plan::tryFrom(self::catalogue()[$priceId]['tier'] ?? '');
    }

    /**
     * The billing cycle a Stripe price is charged on, or null when the config
     * does not say.
     *
     * Null is reported and never guessed. A tier is not a price: `pro` sold
     * monthly and annually is two prices, and a screen that assumed one told a
     * customer what they were paying on no evidence. That shipped, and a billing
     * page read "billed annually" over a monthly charge.
     */
    public static function cycleForPrice(?string $priceId): ?BillingCycle
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        return self::catalogue()[$priceId]['cycle'] ?? null;
    }

    /**
     * The Stripe price that sells [$plan] on [$cycle], or null when none does.
     *
     * An exact pair match, never a nearest one. A checkout asks for the price
     * behind the figure it has just shown the customer, so answering with the
     * tier's other price charges an amount the screen never displayed. A tier
     * this deployment sells one way only therefore refuses the other way rather
     * than substituting.
     */
    public static function priceFor(Plan $plan, BillingCycle $cycle): ?string
    {
        foreach (self::catalogue() as $priceId => $entry) {
            if ($entry['tier'] === $plan->value && $entry['cycle'] === $cycle) {
                return $priceId;
            }
        }

        return null;
    }

    /**
     * The `cashier.plans` map, normalised and with unusable entries stripped.
     *
     * Two forms are accepted, matching `magic-starter-laravel`'s own reader so
     * that plan 4's swap onto the package is a deletion rather than a rewrite:
     *
     *     'price_pro'        => 'pro',
     *     'price_pro_annual' => ['tier' => 'pro', 'cycle' => 'annual'],
     *
     * A bare value is read as MONTHLY, which is a guess this array cannot check
     * (Stripe knows the interval, the config does not), so a price that is
     * annual has to say so or every screen reports the wrong one over a real
     * charge. An unrecognised cycle word is DROPPED rather than defaulted: a
     * typo costs a 422 on that tier instead of a charge on the wrong price.
     *
     * The empty-key filter is `config/cashier.php`'s own and stays: an unset
     * `CASHIER_PRICE_*` writes `'' => 'pro'`, and a reverse lookup would then
     * hand back the empty string as a real tier's price id.
     *
     * @return array<string, array{tier: string, cycle: BillingCycle}>
     */
    public static function catalogue(): array
    {
        $catalogue = [];

        foreach ((array) config('cashier.plans', []) as $priceId => $entry) {
            $priceId = (string) $priceId;

            if ($priceId === '') {
                continue;
            }

            $tier = is_array($entry) ? ($entry['tier'] ?? null) : $entry;
            $cycle = is_array($entry)
                ? BillingCycle::tryFrom((string) ($entry['cycle'] ?? BillingCycle::Monthly->value))
                : BillingCycle::Monthly;

            if (! is_string($tier) || $tier === '' || $cycle === null) {
                continue;
            }

            $catalogue[$priceId] = ['tier' => $tier, 'cycle' => $cycle];
        }

        return $catalogue;
    }
}
