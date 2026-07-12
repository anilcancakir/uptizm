<?php

namespace App\Services\Ai;

/**
 * The single seam between the monitoring core and the weekly-digest LLM.
 *
 * Cloned from {@see IncidentAnalysisGateway} for a different moment in the
 * lifecycle: instead of summarizing a single incident, this gateway narrates
 * a team's whole week (aggregate uptime, the trend against the prior week,
 * and the incidents opened during the window). Swapping the underlying
 * pre-1.0 `laravel/ai` package, the model, or the whole provider is a
 * one-file change in {@see LaravelAiDigestGateway} and never leaks upstream.
 *
 * The gateway only NARRATES; it never mutates a monitor, an incident, or the
 * team's plan.
 */
interface DigestGateway
{
    /**
     * Narrate a team's week from its aggregate uptime and incidents.
     */
    public function summarize(DigestPayload $payload): DigestResult;
}
