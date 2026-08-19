<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GatedFeature;
use App\Enums\IncidentDraftKind;
use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Team;
use App\Services\Ai\IncidentDraftService;
use App\Services\Billing\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped drafting endpoints:
 * `POST /incidents/{incident}/draft-update` and `.../draft-postmortem`.
 *
 * Two routes rather than one with a `kind` body field, because the two are
 * different capabilities on different readers: one writes text bound for a
 * public status page, the other an internal document. Separate routes let them
 * be rate-limited, gated and logged apart later without a migration of the
 * client's request shape.
 *
 * POST and not GET although nothing is stored: each call spends an AI budget
 * unit, and a GET is the verb a browser, a prefetcher or a retry may repeat on
 * its own.
 */
class IncidentDraftController extends Controller
{
    public function __construct(
        protected IncidentDraftService $incidentDraftService,
    ) {}

    /**
     * Draft the next public status update.
     */
    public function update(Request $request, Incident $incident): JsonResponse
    {
        return $this->draft($request, $incident, IncidentDraftKind::Update);
    }

    /**
     * Draft the postmortem for a resolved incident.
     */
    public function postmortem(Request $request, Incident $incident): JsonResponse
    {
        return $this->draft($request, $incident, IncidentDraftKind::Postmortem);
    }

    /**
     * The shared body: authorize, gate on the plan, draft.
     *
     * The locale travels from the request rather than from the team or the
     * page, because the draft is written for whoever is reading the app right
     * now and they are the one who will edit it.
     */
    protected function draft(Request $request, Incident $incident, IncidentDraftKind $kind): JsonResponse
    {
        $this->authorizeTeam($request, $incident);

        $team = Team::find($request->user()->current_team_id);
        if ($team !== null) {
            (new PlanGate)->assertAiLevel($team, 'analysis', GatedFeature::AiIncidentDrafting);
        }

        // `posting_as` is the stage selected in the composer, which is what the
        // update will be stamped with and is not always where the incident
        // stands. Validated against the enum rather than trusted: it reaches a
        // prompt, and an unrecognised value is dropped rather than passed
        // through as a stage nobody defined.
        $postingAs = $request->string('posting_as')->toString();
        $postingAs = IncidentStatus::tryFrom($postingAs)?->value;

        $result = $this->incidentDraftService->draftFor(
            $incident,
            $kind,
            app()->getLocale(),
            $postingAs,
        );

        return response()->json(['data' => $result->toArray()]);
    }

    /**
     * Guard team ownership, masking a foreign incident as 404.
     */
    protected function authorizeTeam(Request $request, Incident $incident): void
    {
        abort_unless(
            $incident->team_id === $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }
}
