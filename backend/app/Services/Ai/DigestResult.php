<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;

/**
 * The immutable weekly narration the digest LLM produces from a team's
 * aggregate uptime and incidents.
 *
 * Mirrors {@see IncidentAnalysisResult}'s shape: a SUMMARY, never an edit or
 * an alert. It never mutates any monitor or incident; the operator reads it
 * as a standalone weekly recap.
 *
 * The `summary` and `highlights` are already allowlist-cleaned: any
 * incident id or monitor id the model cited that was not in the payload's
 * owned catalog has been stripped before this object was built.
 * `strippedCitations` records what was removed so the caller can audit the
 * hallucination rate.
 */
readonly class DigestResult
{
    /**
     * @param  string  $summary  The allowlist-cleaned weekly narration.
     * @param  AiConfidence  $confidence  How strongly the summary is supported by the evidence.
     * @param  list<string>  $highlights  Allowlist-cleaned trend/highlight bullets.
     * @param  list<string>  $strippedCitations  Out-of-catalog citations removed from the summary/highlights.
     */
    public function __construct(
        public string $summary,
        public AiConfidence $confidence,
        public array $highlights,
        public array $strippedCitations = [],
    ) {}

    /**
     * Flatten to the snake_case wire shape the API response returns.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'confidence' => $this->confidence->value,
            'highlights' => $this->highlights,
            'stripped_citations' => $this->strippedCitations,
        ];
    }
}
