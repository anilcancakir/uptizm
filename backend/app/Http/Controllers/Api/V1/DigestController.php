<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GatedFeature;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateWeeklyDigest;
use App\Models\Team;
use App\Models\TeamDigest;
use App\Services\Billing\PlanGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped weekly-digest read endpoint: `GET /incidents/digest`.
 *
 * Reads the latest {@see TeamDigest} row {@see GenerateWeeklyDigest}
 * persisted for the current team; it never runs the digest LLM synchronously
 * from the request, matching {@see IncidentAnalysisController}'s
 * team-scoping shape but without the analysis service call, since generation
 * already happened on the queue.
 */
class DigestController extends Controller
{
    /**
     * Return the latest persisted weekly digest for the current team.
     */
    public function index(Request $request): JsonResponse
    {
        $team = Team::find($request->user()->current_team_id);
        if ($team !== null) {
            (new PlanGate)->assertAiLevel($team, GenerateWeeklyDigest::AI_LEVEL, GatedFeature::AiWeeklyDigest);
        }

        $digest = TeamDigest::query()
            ->forTeam((string) $request->user()->current_team_id)
            ->orderByDesc('generated_at')
            ->first();

        abort_if($digest === null, HttpResponse::HTTP_NOT_FOUND, 'No digest generated yet.');

        return response()->json([
            'data' => [
                'week_start' => $digest->week_start?->format('Y-m-d'),
                'week_end' => $digest->week_end?->format('Y-m-d'),
                'uptime_percent' => $digest->uptime_percent,
                'incident_count' => $digest->incident_count,
                'confidence' => $digest->confidence->value,
                'summary' => $digest->summary,
                'highlights' => $digest->highlights,
                'stripped_citations' => $digest->stripped_citations,
                'generated_at' => $digest->generated_at?->toIso8601String(),
            ],
        ]);
    }
}
