<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;
use App\Enums\AiDegradeReason;

/**
 * The immutable answer the floating-assistant LLM produces from a team's
 * monitors/incidents telemetry and the operator's question.
 *
 * Mirrors {@see IncidentAnalysisResult}'s shape: an ANSWER, never an edit or
 * an action. It never mutates any monitor or incident; the operator reads it
 * as a standalone reply inside the floating-assistant panel.
 *
 * The `answer` is already allowlist-cleaned: any monitor id or incident id
 * the model cited that was not in the payload's owned catalog has been
 * stripped before this object was built. `strippedCitations` records what was
 * removed so the caller can audit the hallucination rate.
 */
readonly class AssistantResult
{
    /**
     * @param  string  $answer  The allowlist-cleaned answer.
     * @param  AiConfidence  $confidence  How strongly the answer is supported by the team's telemetry.
     * @param  list<string>  $strippedCitations  Out-of-catalog citations removed from the answer.
     * @param  AiDegradeReason|null  $degradeReason  Set when no model produced this answer, so the
     *                                               client can draw it as a system note instead of a
     *                                               reply. Null on every real answer.
     *
     *                                               It exists because the over-budget path returns an
     *                                               `AssistantResult` like any other, and the client
     *                                               renders `answer` straight into the chat bubble: an
     *                                               operator over budget was reading a canned sentence
     *                                               attributed to Uptizm AI with nothing to mark it as
     *                                               the system speaking. {@see IncidentAnalysisResult}
     *                                               has carried this field since it shipped.
     */
    public function __construct(
        public string $answer,
        public AiConfidence $confidence,
        public array $strippedCitations = [],
        public ?AiDegradeReason $degradeReason = null,
    ) {}

    /**
     * Flatten to the snake_case wire shape the API response returns.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'confidence' => $this->confidence->value,
            'stripped_citations' => $this->strippedCitations,
            'degrade_reason' => $this->degradeReason?->value,
        ];
    }
}
