<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Team;
use App\Services\Ai\IncidentAnalysisService;
use App\Services\Billing\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped post-incident RCA endpoint: `GET /incidents/{incident}/analysis`.
 *
 * Delegates the timeline+checks composition and the AI-budget guard to
 * {@see IncidentAnalysisService}; this controller only authorizes team
 * ownership (masking a foreign incident as 404, matching
 * {@see IncidentController::authorizeTeam()}) and shapes the response.
 */
class IncidentAnalysisController extends Controller
{
    public function __construct(
        protected IncidentAnalysisService $incidentAnalysisService,
    ) {}

    /**
     * Summarize the likely root cause of an incident.
     */
    public function show(Request $request, Incident $incident): JsonResponse
    {
        $this->authorizeTeam($request, $incident);

        $team = Team::find($request->user()->current_team_id);
        if ($team !== null) {
            (new PlanGate)->assertAiLevel($team, 'analysis', 'AI incident analysis');
        }

        $result = $this->incidentAnalysisService->analyzeFor($incident);

        return response()->json([
            'data' => $result->toArray(),
        ]);
    }

    /**
     * Guard team ownership, masking a foreign incident as 404.
     *
     * A 403 would confirm the incident exists; the 404 mask keeps the
     * existence of another team's incidents hidden.
     */
    protected function authorizeTeam(Request $request, Incident $incident): void
    {
        abort_unless(
            $incident->team_id === $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }
}
