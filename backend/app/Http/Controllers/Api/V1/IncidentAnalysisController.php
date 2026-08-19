<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GatedFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnalysisFeedbackRequest;
use App\Models\AiAnalysisFeedback;
use App\Models\AiIncidentAnalysis;
use App\Models\Incident;
use App\Models\Team;
use App\Services\Ai\IncidentAnalysisService;
use App\Services\Ai\ResolvedAnalysis;
use App\Services\Billing\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped post-incident RCA endpoints: `GET /incidents/{incident}/analysis`
 * and `POST /incidents/{incident}/analysis/feedback`.
 *
 * Delegates the evidence composition, the store read-through and the AI-budget
 * guard to {@see IncidentAnalysisService}; this controller only authorizes team
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
     *
     * `?refresh=1` is the retry button and the only path that asks the model
     * again when a stored answer already covers the current evidence. Every
     * other read is served from the store when it can be, because the endpoint
     * spends a budget unit per model call and a page open is not a new question.
     */
    public function show(Request $request, Incident $incident): JsonResponse
    {
        $this->authorizeTeam($request, $incident);

        $team = Team::find($request->user()->current_team_id);
        if ($team !== null) {
            (new PlanGate)->assertAiLevel($team, 'analysis', GatedFeature::AiIncidentAnalysis);
        }

        $resolved = $this->incidentAnalysisService->storedAnalysisFor(
            $incident,
            $request->boolean('refresh'),
        );

        return response()->json([
            'data' => $this->present($resolved, (string) $request->user()->getKey()),
        ]);
    }

    /**
     * Record this operator's rating of a stored analysis.
     *
     * The response returns the analysis exactly as {@see self::show()} would,
     * so the client re-renders from one decoder instead of patching a field it
     * guessed the shape of.
     */
    public function feedback(StoreAnalysisFeedbackRequest $request, Incident $incident): JsonResponse
    {
        $this->authorizeTeam($request, $incident);

        $analysis = AiIncidentAnalysis::query()
            ->where('incident_id', $incident->getKey())
            ->where('team_id', $request->user()->current_team_id)
            ->find($request->string('analysis_id')->toString());

        // Same 404 mask as a foreign incident, and for the same reason: a 403
        // here would confirm that an analysis with this id exists somewhere.
        abort_if($analysis === null, HttpResponse::HTTP_NOT_FOUND);

        AiAnalysisFeedback::updateOrCreate(
            [
                'analysis_id' => $analysis->getKey(),
                'user_id' => $request->user()->getKey(),
            ],
            [
                'team_id' => $analysis->team_id,
                'helpful' => $request->boolean('helpful'),
            ],
        );

        return response()->json([
            'data' => $this->present(
                new ResolvedAnalysis($analysis->result, $analysis),
                (string) $request->user()->getKey(),
            ),
        ]);
    }

    /**
     * The wire shape: the result, the id of the row it came from, and this
     * reader's own vote.
     *
     * Both extra keys are always PRESENT and carry null on the degrade path,
     * following `degrade_reason`'s own rule: the client distinguishes a null
     * ("there is no answer to rate") from an absent key ("this is a shape I do
     * not know"), and a missing id is exactly what tells it to render no
     * feedback buttons.
     *
     * `feedback` is per-USER rather than the team's aggregate. A thumbs-up is a
     * button state the operator needs reflected back, and showing them a
     * colleague's vote as their own would misreport what they clicked.
     *
     * @return array<string, mixed>
     */
    protected function present(ResolvedAnalysis $resolved, string $userId): array
    {
        $vote = $resolved->stored?->feedback()
            ->where('user_id', $userId)
            ->first();

        return $resolved->result + [
            'id' => $resolved->stored === null ? null : (string) $resolved->stored->getKey(),
            'feedback' => $vote === null ? null : $vote->helpful,
        ];
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
