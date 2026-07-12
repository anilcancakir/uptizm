<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;

/**
 * A deterministic post-incident RCA gateway for tests and offline runs.
 *
 * Bound in place of {@see LaravelAiIncidentAnalysisGateway} so no real
 * Anthropic call is ever made in CI. It returns a fixed
 * {@see IncidentAnalysisResult} independent of the payload contents, so
 * assertions stay byte-stable.
 *
 * It still honors the boundary contract: it only SUMMARIZES, it never
 * mutates the incident, and its narration cites nothing that is not in
 * every payload's catalog.
 */
class FakeIncidentAnalysisGateway implements IncidentAnalysisGateway
{
    /**
     * Return a fixed, boundary-safe RCA summary for any incident.
     */
    public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
    {
        return new IncidentAnalysisResult(
            summary: 'Deterministic RCA stub: elevated response time on the affected monitor correlates with the incident window.',
            confidence: AiConfidence::Medium,
            contributingFactors: [
                'Response time exceeded the configured threshold during the incident window.',
            ],
            strippedCitations: [],
        );
    }
}
