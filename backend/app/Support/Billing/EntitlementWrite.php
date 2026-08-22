<?php

namespace App\Support\Billing;

use App\Actions\Billing\WriteTeamEntitlement;
use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Models\Team;
use Carbon\CarbonInterface;

/**
 * One rail's complete claim about what a team is entitled to, and when it said
 * so.
 *
 * This is the only shape {@see WriteTeamEntitlement} accepts, and it is a value
 * object rather than a parameter list for two reasons. Eleven positional
 * arguments of which four are nullable timestamps is a call site nobody can
 * read, and worse, two of those arguments can be silently transposed; and the
 * ordering rules the action enforces need `provider` and `eventAt` to travel
 * WITH the tier they justify. A caller that can pass a tier without saying which
 * rail claimed it and when could not be checked at all.
 *
 * `provider` and `status` are enums, not strings, deliberately. A rail's own
 * vocabulary is mapped by the feeder that speaks it, before it reaches here, so
 * an unrecognised word cannot arrive in a column that the neutral wire and the
 * `grants()` predicates both read.
 *
 * `providerStatus` is the other half of that trade: the rail's word survives
 * verbatim, in a field that is opaque debug text and gates nothing.
 */
readonly class EntitlementWrite
{
    /**
     * @param  Team  $team  the subscriber whose entitlement this claim is about
     * @param  Plan  $plan  the tier the rail says is owed
     * @param  PlanStatus  $status  where that tier stands, in neutral words
     * @param  BillingProvider  $provider  the rail making the claim
     * @param  CarbonInterface  $eventAt  the SOURCE event's own timestamp, not
     *                                    the moment of delivery and not `now()`.
     *                                    This is what makes an out-of-order
     *                                    delivery detectable at all, so a feeder
     *                                    passing the receipt time instead of the
     *                                    event time disarms the monotonic rule
     *                                    while looking correct.
     * @param  string|null  $providerStatus  the rail's own status word, verbatim
     * @param  string|null  $productId  the rail-native product or price id
     * @param  CarbonInterface|null  $currentPeriodEnd  when the paid period ends,
     *                                                  whether or not it renews
     * @param  bool|null  $renews  auto-renew state; null means the rail has not
     *                             said, which is not the same claim as false
     * @param  CarbonInterface|null  $gracePeriodEndsAt  end of a dunning window
     * @param  string|null  $manageUrl  where the customer manages this
     *                                  subscription, on the rail that sold it
     */
    public function __construct(
        public Team $team,
        public Plan $plan,
        public PlanStatus $status,
        public BillingProvider $provider,
        public CarbonInterface $eventAt,
        public ?string $providerStatus = null,
        public ?string $productId = null,
        public ?CarbonInterface $currentPeriodEnd = null,
        public ?bool $renews = null,
        public ?CarbonInterface $gracePeriodEndsAt = null,
        public ?string $manageUrl = null,
    ) {}
}
