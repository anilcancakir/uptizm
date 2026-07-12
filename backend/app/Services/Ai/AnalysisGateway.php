<?php

namespace App\Services\Ai;

/**
 * The single seam between the monitoring core and the monitor-setup analysis
 * LLM.
 *
 * Cloned from {@see AnomalyTriageGateway} for a different moment in the
 * lifecycle: instead of labeling an already-fired anomaly, this gateway
 * analyzes a single exploratory probe of a URL the operator is about to turn
 * into a monitor and suggests a starting configuration. Swapping the
 * underlying pre-1.0 `laravel/ai` package, the model, or the whole provider
 * is a one-file change in {@see LaravelAiAnalysisGateway} and never leaks
 * upstream.
 *
 * The gateway only SUGGESTS a configuration; the operator still submits the
 * create-monitor form and can override every field.
 */
interface AnalysisGateway
{
    /**
     * Suggest a monitor configuration from a probe result and its optional
     * detector output.
     */
    public function analyze(AnalysisPayload $payload): AnalysisResult;
}
