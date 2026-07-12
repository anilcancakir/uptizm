<?php

namespace App\Services\Ai;

/**
 * The single seam between the monitoring core and the post-incident RCA
 * (root-cause analysis) LLM.
 *
 * Cloned from {@see AnalysisGateway} for a different moment in the
 * lifecycle: instead of prefilling a monitor from an exploratory probe, this
 * gateway summarizes the likely root cause of an already-resolved-or-live
 * incident from its timeline and the checks recorded against its affected
 * monitors. Swapping the underlying pre-1.0 `laravel/ai` package, the model,
 * or the whole provider is a one-file change in
 * {@see LaravelAiIncidentAnalysisGateway} and never leaks upstream.
 *
 * The gateway only SUMMARIZES; it never edits the incident timeline or
 * changes its lifecycle.
 */
interface IncidentAnalysisGateway
{
    /**
     * Summarize the likely root cause of an incident from its timeline and
     * recorded checks.
     */
    public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult;
}
