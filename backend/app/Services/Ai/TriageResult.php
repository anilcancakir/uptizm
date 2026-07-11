<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;

/**
 * The immutable label the triage LLM assigns to a statistical anomaly.
 *
 * Critically, this is a LABEL, not a verdict: `confirmed = false` never means
 * "suppress the anomaly". The statistical detector already fired; the caller
 * decides what to do, and the anomaly always stands. This object only narrates
 * and tiers it.
 *
 * The `recommendation` is already allowlist-cleaned: any check_id, metric_key,
 * or region the model cited that was not in the payload's owned catalog has been
 * stripped before this object was built. `strippedCitations` records what was
 * removed so the caller can audit the hallucination rate and enforce the
 * boundary again before persisting.
 */
readonly class TriageResult
{
    /**
     * @param  bool  $confirmed  Whether the model reads the evidence as a real
     *                           deviation worth an operator's attention (a label,
     *                           never a suppression switch).
     * @param  string  $severity  The severity tier: `critical`, `warn`, or `info`.
     * @param  AiConfidence  $confidence  How strongly the label is presented.
     * @param  string  $recommendation  The allowlist-cleaned narration.
     * @param  list<string>  $strippedCitations  Out-of-catalog citations removed
     *                                           from the recommendation.
     */
    public function __construct(
        public bool $confirmed,
        public string $severity,
        public AiConfidence $confidence,
        public string $recommendation,
        public array $strippedCitations = [],
    ) {}

    /**
     * Flatten to the snake_case wire shape the persistence layer stores.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'confirmed' => $this->confirmed,
            'severity' => $this->severity,
            'confidence' => $this->confidence->value,
            'recommendation' => $this->recommendation,
            'stripped_citations' => $this->strippedCitations,
        ];
    }
}
