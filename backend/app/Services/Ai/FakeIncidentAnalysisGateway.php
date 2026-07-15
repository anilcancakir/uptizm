<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;
use App\Enums\EvidenceSource;

/**
 * A deterministic post-incident RCA gateway for tests and offline runs.
 *
 * Bound in place of {@see LaravelAiIncidentAnalysisGateway} so no real
 * Anthropic call is ever made in CI. It returns a fixed
 * {@see IncidentAnalysisResult} independent of the payload contents, so
 * assertions stay byte-stable.
 *
 * It still honors the boundary contract: it only SUMMARIZES, it never
 * mutates the incident, its narration cites nothing that is not in every
 * payload's catalog, and every evidence row carries an in-enum
 * {@see EvidenceSource} source (never free text).
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
            evidenceFor: [
                [
                    'label' => 'Response time breached the threshold',
                    'detail' => 'The recorded checks show latency above the configured bound across the incident window.',
                    'source' => EvidenceSource::Check->value,
                ],
            ],
            evidenceAgainst: [
                [
                    'label' => 'No single-region pattern',
                    'detail' => 'The affected monitor did not fail in only one region, which would point at a probe fault.',
                    'source' => EvidenceSource::Monitor->value,
                ],
            ],
            suggestedActions: [
                [
                    'title' => 'Confirm the origin health',
                    'rationale' => 'Latency elevated across the whole window points at the monitored endpoint rather than the probes.',
                ],
            ],
        );
    }
}
