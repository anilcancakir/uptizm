<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AiConfidence;
use App\Enums\AiDegradeReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskAssistantRequest;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AssistantGateway;
use App\Services\Ai\AssistantPayload;
use App\Services\Ai\AssistantResult;
use App\Services\Ai\NonConformingAnalysisException;
use App\Services\Billing\PlanGate;
use App\Support\Ai\PromptLanguage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;

/**
 * Team-scoped floating-assistant endpoint: `POST /assistant`.
 *
 * Mirrors {@see IncidentAnalysisController}'s budget-guard shape: the
 * per-team AI budget is spent AT THIS call site, and the payload composition
 * (folding the current team's monitors/incidents into a fenced
 * {@see AssistantPayload}) lives here rather than a separate service, since
 * the evidence is a flat read of the acting team's own telemetry.
 *
 * The two ways it can fail to reach a model answer are deliberately NOT the same
 * outcome, and the docblock used to describe only the first:
 *
 * - OVER BUDGET is a 200. Nothing is broken; the team asked for more than it is
 *   allowed today. It answers with a localized sentence carrying
 *   {@see AiDegradeReason::BudgetExhausted}, so the panel keeps working and the
 *   client can draw it as a system note rather than as a reply.
 * - A PROVIDER FAILURE is a 503. Unlike every other AI surface here, this one
 *   cannot degrade to a deterministic answer: an analysis card falls back to a
 *   summary derived from the incident's own fields, but this endpoint answers a
 *   question the operator wrote, and there is no canned version of that. Saying
 *   the service is unavailable is the honest move; putting a filler sentence in
 *   the chat as though the assistant had replied is not.
 *
 * Before both of those it caught nothing, so a provider hiccup left as a 500
 * carrying Laravel's own English "Server Error" and no log line naming the
 * failure.
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

        if (! $this->budget->tryConsume($teamId)) {
            return response()->json([
                'data' => $this->overBudgetAnswer()->toArray(),
            ]);
        }

        // Nothing caught these, so a provider hiccup left as a 500 whose body is
        // Laravel's own English "Server Error", and the client puts the backend's
        // message straight into its toast. Reproduced by accident against the
        // live provider on 2026-08-14: `cURL error 28: Connection timed out after
        // 10001 milliseconds`.
        //
        // A 503 rather than a degraded 200, and that is the difference from every
        // other AI surface here. An analysis card can degrade to a summary
        // derived from the incident's own fields, which is a real answer to a
        // fixed question. This endpoint answers a question the operator wrote, so
        // there is no deterministic version of it: the honest move is to say the
        // service is unavailable and let them ask again, not to put a canned
        // sentence in the chat as though the assistant had replied.
        try {
            $result = $this->gateway->answer($payload);
        } catch (ConnectionException|RequestException $exception) {
            return $this->unavailable('The AI assistant could not reach the service.', [
                'failure' => class_basename($exception),
                'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'exception' => $exception->getMessage(),
            ]);
        } catch (AiException $exception) {
            // Not redundant with the pair above, and the most ordinary provider
            // bad day there is: `laravel/ai` raises a PLAIN `AiException` for an
            // error body delivered IN-BAND with HTTP 200, so it descends from
            // neither this app's exceptions nor Guzzle's. The CLASS but not the
            // message, because the message is text the provider chose.
            return $this->unavailable('The AI assistant provider could not complete the request.', [
                'failure' => class_basename($exception),
            ]);
        } catch (NonConformingAnalysisException $exception) {
            // The gateway rejects untrusted structured output after one retry. It
            // used to throw a bare `RuntimeException`, which a caller could only
            // catch by catching every runtime error.
            return $this->unavailable('The AI assistant output could not be trusted.', [
                'failure' => class_basename($exception),
            ]);
        }

        return response()->json([
            'data' => $result->toArray(),
        ]);
    }

    /**
     * Log an AI failure and answer 503 with a sentence the operator can read.
     *
     * The log line is the half a bare 500 never produced: `IncidentAnalysisService`
     * writes the failure class and status for exactly this reason, and one reason
     * code covering three provider failures only stays diagnosable if the finer
     * detail is written down somewhere.
     *
     * The response `message` is what the client shows, so it goes through
     * `__()`. `SetApiLocale` has already applied the caller's own language by the
     * time this runs.
     *
     * @param  array<string, mixed>  $context
     */
    protected function unavailable(string $logMessage, array $context): JsonResponse
    {
        Log::warning($logMessage, $context);

        return response()->json([
            'message' => __('assistant.unavailable'),
        ], 503);
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
            // No URL. The whole roster is JSON-encoded into the prompt, and a
            // monitor address of the shape `https://host/api/v1/<32 hex>/status`
            // carries its credential in the path. The name answers every
            // question an operator asks the assistant about a monitor.
            'status' => $monitor->effectiveStatus()?->value,
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
            // The one AI surface with a live request behind it, so this is
            // the request's locale rather than the team's: SetApiLocale has
            // already resolved the caller's own stored preference.
            language: PromptLanguage::nameFor(app()->getLocale()),
        );
    }

    /**
     * The answer for a team that has spent its daily AI budget, produced without
     * calling the model at all.
     *
     * A 200 rather than the 503 a provider failure gets, because nothing is
     * broken: the team asked for more than it is allowed today, which is a fact
     * about them and not an outage. It stays an `AssistantResult` so the panel
     * keeps working.
     *
     * Two things it used not to do. The sentence was a hardcoded English literal,
     * and the client renders `answer` straight into the chat bubble, so a Turkish
     * operator read an English sentence attributed to Uptizm AI. And it carried no
     * `degradeReason`, so nothing on the wire distinguished it from something the
     * assistant had actually worked out.
     */
    protected function overBudgetAnswer(): AssistantResult
    {
        return new AssistantResult(
            answer: __('assistant.degraded_budget'),
            confidence: AiConfidence::Low,
            strippedCitations: [],
            degradeReason: AiDegradeReason::BudgetExhausted,
        );
    }
}
