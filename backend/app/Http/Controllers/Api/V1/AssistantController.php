<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AiConfidence;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskAssistantRequest;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AssistantGateway;
use App\Services\Ai\AssistantPayload;
use App\Services\Ai\AssistantResult;
use App\Services\Billing\PlanGate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Team-scoped floating-assistant endpoint: `POST /assistant`.
 *
 * Mirrors {@see IncidentAnalysisController}'s budget-guard shape: the
 * per-team AI budget is spent AT THIS call site, and the payload composition
 * (folding the current team's monitors/incidents into a fenced
 * {@see AssistantPayload}) lives here rather than a separate service, since
 * the evidence is a flat read of the acting team's own telemetry. Over
 * budget degrades to a deterministic, non-LLM answer, never calling the LLM,
 * so the endpoint still answers.
 */
class AssistantController extends Controller
{
    /**
     * Maximum number of recent monitors folded into the assistant's context.
     */
    private const MAX_MONITORS = 20;

    /**
     * Maximum number of recent incidents folded into the assistant's context.
     */
    private const MAX_INCIDENTS = 20;

    public function __construct(
        protected AssistantGateway $gateway,
        protected AiBudget $budget,
    ) {}

    /**
     * Answer a question about the current team's monitors/incidents.
     */
    public function answer(AskAssistantRequest $request): JsonResponse
    {
        $teamId = (string) $request->user()->current_team_id;

        $team = Team::find($teamId);
        if ($team !== null) {
            (new PlanGate)->assertAiLevel($team, 'analysis', 'The AI assistant');
        }

        $monitors = Monitor::query()
            ->where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->limit(self::MAX_MONITORS)
            ->get();

        $incidents = Incident::query()
            ->where('team_id', $teamId)
            ->orderByDesc('started_at')
            ->limit(self::MAX_INCIDENTS)
            ->get();

        $payload = $this->buildPayload($teamId, (string) $request->validated('question'), $monitors, $incidents);

        $result = $this->budget->tryConsume($teamId)
            ? $this->gateway->answer($payload)
            : $this->deterministicAnswer();

        return response()->json([
            'data' => $result->toArray(),
        ]);
    }

    /**
     * Hydrate the fenced payload from the current team's monitors/incidents
     * and the operator's untrusted question.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @param  Collection<int, Incident>  $incidents
     */
    protected function buildPayload(string $teamId, string $question, Collection $monitors, Collection $incidents): AssistantPayload
    {
        $trustedMonitors = $monitors->map(fn (Monitor $monitor) => [
            'monitor_id' => (string) $monitor->id,
            'name' => $monitor->name,
            'url' => $monitor->url,
            'status' => $monitor->last_status?->value,
        ])->all();

        $trustedIncidents = $incidents->map(fn (Incident $incident) => [
            'incident_id' => (string) $incident->id,
            'title' => $incident->title,
            'severity' => $incident->severity?->value ?? 'unknown',
            'lifecycle' => $incident->lifecycle?->value ?? 'unknown',
            'started_at' => $incident->started_at?->toIso8601String() ?? '',
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
        ])->all();

        return new AssistantPayload(
            teamId: $teamId,
            question: $question,
            monitors: $trustedMonitors,
            incidents: $trustedIncidents,
            knownMonitorIds: array_column($trustedMonitors, 'monitor_id'),
            knownIncidentIds: array_column($trustedIncidents, 'incident_id'),
        );
    }

    /**
     * Build a deterministic answer used when the team is over its daily AI
     * budget, so the LLM is never called.
     */
    protected function deterministicAnswer(): AssistantResult
    {
        return new AssistantResult(
            answer: 'AI assistant budget exhausted for today: please check the monitors and incidents lists directly, or ask again tomorrow.',
            confidence: AiConfidence::Low,
            strippedCitations: [],
        );
    }
}
