<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;

/**
 * A deterministic triage gateway for tests and offline runs.
 *
 * Bound in place of {@see LaravelAiTriageGateway} so no real Anthropic call is
 * ever made in CI. It returns a fixed {@see TriageResult} independent of the
 * payload contents, so assertions stay byte-stable.
 *
 * It still honors the boundary contract: it only LABELS, it never suppresses,
 * and its recommendation cites nothing that is not in every payload's catalog.
 */
class FakeAnomalyTriageGateway implements AnomalyTriageGateway
{
    /**
     * Return a fixed, boundary-safe label for any anomaly.
     */
    public function triage(TriagePayload $payload): TriageResult
    {
        return new TriageResult(
            confirmed: true,
            severity: 'warn',
            confidence: AiConfidence::Medium,
            recommendation: 'Deterministic triage stub: elevated deviation on the observed signal.',
            strippedCitations: [],
        );
    }
}
