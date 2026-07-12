<?php

namespace App\Http\Resources;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for the current team's billing entitlement + subscription.
 *
 * `plan`/`plan_status` come from {@see Team::entitledPlan()}, the single
 * entitlement read; the `stripe_*` fields mirror the team's "default"
 * Cashier subscription when one exists and are null otherwise (free tier,
 * or a subscription-less team).
 *
 * @property Team $resource
 */
class SubscriptionResource extends JsonResource
{
    /**
     * Transform the team's entitlement + subscription into its wire shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->resource->subscription('default');

        return [
            'plan' => $this->resource->entitledPlan()->value,
            'plan_status' => $this->resource->plan_status,
            'subscribed' => $subscription !== null && $subscription->active(),
            'on_grace_period' => $subscription?->onGracePeriod() ?? false,
            'stripe_price' => $subscription?->stripe_price,
            'stripe_status' => $subscription?->stripe_status,
            'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
            'ends_at' => $subscription?->ends_at?->toIso8601String(),
        ];
    }
}
