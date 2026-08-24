<?php

namespace App\Enums;

/**
 * How often a subscription is charged.
 *
 * It exists because a tier is not a price. {@see Plan::Pro} sold at a monthly
 * rate and again at a discounted annual rate is ONE tier and TWO Stripe prices,
 * and three separate things have to agree which of the two is in play: the
 * figure the billing screen shows, the price the checkout charges, and the
 * interval the renewal line names. With no cycle travelling between them those
 * answers came from three different places and disagreed. Measured against a
 * live Stripe test account: the screen offered an annual discount at $29/mo and
 * Stripe charged $34.00 monthly.
 *
 * The two words match `magic_payments`' Dart `BillingCycle` and
 * `magic-starter-laravel`'s cycle constants exactly, so a value crosses the wire
 * in both directions with no translation table in between. Changing one of them
 * is a three-repository change.
 *
 * There is no `Unknown` case, deliberately, and every reader treats a null as
 * "nothing said" instead. Monthly and annual are the only two cycles there are,
 * so a fallback member would be a claim about what a customer is being charged,
 * which is the defect this type was added to close.
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';
}
