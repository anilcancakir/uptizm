<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;
use App\Enums\AiDegradeReason;
use App\Enums\EvidenceSource;

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
 *
 * `evidenceFor`, `evidenceAgainst`, and `suggestedActions` are the enriched,
 * nested wire fields the incident detail screen renders. Every evidence row is
 * allowlist-cleaned and carries an {@see EvidenceSource}-constrained
 * source; the deterministic and non-conforming fallbacks leave them empty. The
 * LLM path and every fallback path therefore return the IDENTICAL wire shape
 * (empty arrays, never null, never omitted), so the client renders no hole and
 * never sees a fabricated source.
 *
 * `degradeReason` is the one field that differs between those paths: null when
 * the model answered, and the {@see AiDegradeReason} case naming what went
 * wrong on each fallback. It is what lets the client narrate a degrade in the
 * operator's own language instead of reading an English clause out of
 * `summary`.
 */
readonly class IncidentAnalysisResult
{
    /**
     * @param  string  $summary  The allowlist-cleaned root-cause narration.
     * @param  AiConfidence  $confidence  How strongly the summary is supported by the evidence.
     * @param  list<string>  $contributingFactors  Allowlist-cleaned contributing-factor bullets.
     * @param  list<string>  $strippedCitations  Out-of-catalog citations removed from every free-text field.
     * @param  list<array{label: string, detail: string, source: string}>  $evidenceFor  Evidence supporting the root cause.
     * @param  list<array{label: string, detail: string, source: string}>  $evidenceAgainst  Evidence that qualifies or contradicts it.
     * @param  list<array{title: string, rationale: string}>  $suggestedActions  Concrete next steps derived from the evidence.
     * @param  AiDegradeReason|null  $degradeReason  Why the baseline was used, or null when the model answered.
     */
    public function __construct(
        public string $summary,
        public AiConfidence $confidence,
        public array $contributingFactors,
        public array $strippedCitations = [],
        public array $evidenceFor = [],
        public array $evidenceAgainst = [],
        public array $suggestedActions = [],
        public ?AiDegradeReason $degradeReason = null,
    ) {}

    /**
     * Flatten to the snake_case wire shape the API response returns.
     *
     * `degrade_reason` is always PRESENT, carrying null on the LLM path rather
     * than being omitted: the client distinguishes null ("the model answered")
     * from an absent key ("this response is a shape I do not know").
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
            'evidence_for' => $this->evidenceFor,
            'evidence_against' => $this->evidenceAgainst,
            'suggested_actions' => $this->suggestedActions,
            'degrade_reason' => $this->degradeReason?->value,
        ];
    }
}
