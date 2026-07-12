<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;

/**
 * The immutable post-incident RCA summary the analysis LLM produces from an
 * incident's timeline and recorded checks.
 *
 * Mirrors {@see AnalysisResult}'s shape: a SUMMARY, never an edit. It never
 * mutates the incident's lifecycle or timeline; the operator reads it as
 * context alongside the incident detail view.
 *
 * The `summary` and `contributingFactors` are already allowlist-cleaned: any
 * check id or monitor id the model cited that was not in the payload's owned
 * catalog has been stripped before this object was built.
 * `strippedCitations` records what was removed so the caller can audit the
 * hallucination rate.
 */
readonly class IncidentAnalysisResult
{
    /**
     * @param  string  $summary  The allowlist-cleaned root-cause narration.
     * @param  AiConfidence  $confidence  How strongly the summary is supported by the evidence.
     * @param  list<string>  $contributingFactors  Allowlist-cleaned contributing-factor bullets.
     * @param  list<string>  $strippedCitations  Out-of-catalog citations removed from the summary.
     */
    public function __construct(
        public string $summary,
        public AiConfidence $confidence,
        public array $contributingFactors,
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
            'contributing_factors' => $this->contributingFactors,
            'stripped_citations' => $this->strippedCitations,
        ];
    }
}
