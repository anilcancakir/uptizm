<?php

namespace App\Services\Ai;

/**
 * The single seam between the monitoring core and the triage LLM.
 *
 * Everything the AI boundary needs (grounding, prompt-injection fencing, the
 * owned-signal allowlist, structured output, provider churn) lives behind this
 * one method. Callers depend only on this contract, so swapping the underlying
 * pre-1.0 `laravel/ai` package, the model, or the whole provider is a one-file
 * change in {@see LaravelAiTriageGateway} and never leaks upstream.
 *
 * The gateway only LABELS an anomaly that the deterministic detector already
 * raised; it never decides whether the anomaly is real and never suppresses it.
 */
interface AnomalyTriageGateway
{
    /**
     * Label and briefly narrate a statistical anomaly from its evidence.
     */
    public function triage(TriagePayload $payload): TriageResult;
}
