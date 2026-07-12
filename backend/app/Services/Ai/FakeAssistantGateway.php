<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;

/**
 * A deterministic floating-assistant gateway for tests and offline runs.
 *
 * Bound in place of {@see LaravelAiAssistantGateway} so no real Anthropic
 * call is ever made in CI. It returns a fixed {@see AssistantResult}
 * independent of the payload contents, so assertions stay byte-stable.
 *
 * It still honors the boundary contract: it only ANSWERS, and its answer
 * cites nothing that is not in every payload's catalog.
 */
class FakeAssistantGateway implements AssistantGateway
{
    /**
     * Return a fixed, boundary-safe answer for any question.
     */
    public function answer(AssistantPayload $payload): AssistantResult
    {
        return new AssistantResult(
            answer: 'Deterministic assistant stub: ask a follow-up question and I will answer from your team\'s current monitors and incidents.',
            confidence: AiConfidence::Medium,
            strippedCitations: [],
        );
    }
}
