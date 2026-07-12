<?php

namespace App\Services\Ai;

/**
 * The single seam between the monitoring core and the floating-assistant LLM.
 *
 * Cloned from {@see IncidentAnalysisGateway} for a different moment in the
 * lifecycle: instead of summarizing one incident or one week, this gateway
 * answers a free-text QUESTION the operator asks about their own team's
 * monitors and incidents. Swapping the underlying pre-1.0 `laravel/ai`
 * package, the model, or the whole provider is a one-file change in
 * {@see LaravelAiAssistantGateway} and never leaks upstream.
 *
 * The gateway only ANSWERS from the fenced team telemetry it is handed; it
 * never mutates a monitor, an incident, or the team's plan, and it never
 * answers outside that fenced context.
 */
interface AssistantGateway
{
    /**
     * Answer a team-scoped question grounded in the team's own telemetry.
     */
    public function answer(AssistantPayload $payload): AssistantResult;
}
