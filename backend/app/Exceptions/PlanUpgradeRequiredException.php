<?php

namespace App\Exceptions;

use App\Enums\GatedFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A feature the team's plan does not entitle, refused with the tier that does.
 *
 * A plain `abort(403, "... available on the Pro plan ...")` carried the tier
 * only inside prose, so a client could either parse English or offer nothing
 * better than a dead-end error. This exception names the tier in the payload
 * (`upgrade.required_plan`), which is what lets the client render an upgrade
 * action that starts checkout for exactly that plan instead of a bare toast.
 *
 * `required_plan` is a `config('plans.tiers.*.id')` key (`pro`, `business`,
 * `enterprise`), the same identifier the billing endpoints accept, NOT the
 * entitled-plan enum (which has no enterprise case). The `message` stays the
 * human sentence the client already surfaces verbatim, so nothing regresses for
 * a consumer that ignores the extra block.
 */
class PlanUpgradeRequiredException extends HttpException
{
    /**
     * @param  string  $requiredPlan  Plan catalog id of the lowest entitling tier.
     * @param  GatedFeature  $feature  What was refused. An enum rather than a label,
     *                                 so the set of refusable features is closed and
     *                                 every one of them has a translation.
     * @param  string|null  $message  Overrides the default sentence, for a refusal
     *                                the generic wording would misdescribe (a spent
     *                                metered allowance rather than a tier the team
     *                                never had). Already localized by the caller.
     */
    public function __construct(
        public readonly string $requiredPlan,
        public readonly GatedFeature $feature,
        ?string $message = null,
    ) {
        parent::__construct(
            HttpResponse::HTTP_FORBIDDEN,
            // Resolved through the catalogue, in the CALLER's language: this
            // sentence is rendered verbatim by three client surfaces, one of
            // which belongs to magic_starter and cannot be reached from the app.
            // It was a hardcoded English sprintf, so a Turkish operator met one
            // English sentence on the upgrade prompt.
            $message ?? __('plans.upgrade_required', [
                'feature' => $this->feature->label(),
                'plan' => ucfirst($requiredPlan),
            ]),
        );
    }

    /**
     * Render the refusal with the machine-readable upgrade target attached.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'upgrade' => [
                'required_plan' => $this->requiredPlan,
                // The localized NAME, matching `message`'s language, not the enum
                // value: this pairs with a human sentence and no client keys off
                // it. `required_plan` is the machine-readable half.
                'feature' => $this->feature->label(),
            ],
        ], HttpResponse::HTTP_FORBIDDEN);
    }
}
