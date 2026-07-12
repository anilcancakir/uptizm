<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AiSuggestionStatus;
use App\Http\Controllers\Controller;
use App\Models\AiSuggestion;
use App\Services\Ai\AiIncidentOpener;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operator decisions on an {@see AiSuggestion} in suggest mode: accept it into
 * a real incident, or dismiss it.
 *
 * Accept is the sensitive path. It is idempotent: a suggestion opens at most
 * one incident no matter how often the endpoint is replayed (a double-tap, a
 * retried request, or two concurrent operators). The one-incident invariant is
 * enforced by a conditional claim under a row lock inside a transaction, not by
 * a read-then-write the client could race.
 *
 * Both actions are team-scoped: a suggestion the caller's current team does not
 * own is a 404, never a 403, so the endpoint never confirms the existence of
 * another team's row.
 */
class AiSuggestionController extends Controller
{
    /**
     * @param  AiIncidentOpener  $incidentOpener  The shared AI-owned incident creator.
     */
    public function __construct(
        protected AiIncidentOpener $incidentOpener,
    ) {}

    /**
     * Accept a suggestion into a single incident and return the incident id.
     *
     * @param  string  $suggestion  Primary key of the suggestion to accept.
     */
    public function accept(Request $request, string $suggestion): JsonResponse
    {
        // 1. Team-scope + existence guard: another team's suggestion is a 404.
        $model = AiSuggestion::query()
            ->forTeam($request->user()->current_team_id)
            ->with('monitor')
            ->findOrFail($suggestion);

        // 2. Already accepted: idempotent no-op. Return the incident it opened
        //    without touching the row again (the common replay path).
        if ($model->accepted_incident_id !== null) {
            return $this->incidentResponse($model->accepted_incident_id);
        }

        $incidentId = DB::transaction(function () use ($model): string {
            // 3. Conditional claim under a row lock: only the first accept whose
            //    row is still pending with no incident wins and opens one. A
            //    concurrent accept that lost the race falls through to reuse.
            $claimed = AiSuggestion::query()
                ->whereKey($model->id)
                ->where('status', AiSuggestionStatus::Pending)
                ->whereNull('accepted_incident_id')
                ->lockForUpdate()
                ->first();

            if ($claimed === null) {
                return (string) AiSuggestion::query()
                    ->whereKey($model->id)
                    ->value('accepted_incident_id');
            }

            // 4. Open exactly one incident via the shared creator, then link it
            //    back and flip the status so a later accept short-circuits.
            $incident = $this->incidentOpener->open($claimed);
            $claimed->forceFill([
                'status' => AiSuggestionStatus::Accepted,
                'accepted_incident_id' => $incident->id,
            ])->save();

            return $incident->id;
        });

        return $this->incidentResponse($incidentId);
    }

    /**
     * Dismiss a suggestion so it drops out of the pending inbox.
     *
     * @param  string  $suggestion  Primary key of the suggestion to dismiss.
     */
    public function dismiss(Request $request, string $suggestion): JsonResponse
    {
        $model = AiSuggestion::query()
            ->forTeam($request->user()->current_team_id)
            ->findOrFail($suggestion);

        $model->forceFill(['status' => AiSuggestionStatus::Dismissed])->save();

        return response()->json([
            'data' => [
                'status' => AiSuggestionStatus::Dismissed->value,
            ],
        ]);
    }

    /**
     * Wrap an incident id in the accept response envelope.
     */
    protected function incidentResponse(string $incidentId): JsonResponse
    {
        return response()->json([
            'data' => [
                'incident_id' => $incidentId,
            ],
        ]);
    }
}
