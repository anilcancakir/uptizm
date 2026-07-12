<?php

namespace App\Services\Ai;

/**
 * The immutable, prefilled monitor configuration the analysis LLM suggests
 * for a URL the operator is about to turn into a monitor.
 *
 * Mirrors {@see TriageResult}'s shape: a SUGGESTION, never a decision. The
 * operator still submits the create-monitor form; this only prefills it so
 * they do not have to guess an interval or a threshold from a single probe.
 *
 * The `rationale` is already allowlist-cleaned: any region the model cited
 * that was not in the payload's owned catalog has been stripped before this
 * object was built. `strippedCitations` records what was removed so the
 * caller can audit the hallucination rate.
 */
readonly class AnalysisResult
{
    /**
     * @param  int  $recommendedIntervalSeconds  The suggested check interval, in seconds.
     * @param  int  $recommendedWarnThresholdMs  The suggested warn-severity response-time bound, in milliseconds.
     * @param  int  $recommendedCriticalThresholdMs  The suggested critical-severity response-time bound, in milliseconds.
     * @param  list<string>  $recommendedRegions  The suggested relay regions to probe from.
     * @param  string  $rationale  The allowlist-cleaned narration behind the suggestion.
     * @param  list<string>  $strippedCitations  Out-of-catalog region citations removed from the rationale.
     */
    public function __construct(
        public int $recommendedIntervalSeconds,
        public int $recommendedWarnThresholdMs,
        public int $recommendedCriticalThresholdMs,
        public array $recommendedRegions,
        public string $rationale,
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
            'recommended_interval_seconds' => $this->recommendedIntervalSeconds,
            'recommended_warn_threshold_ms' => $this->recommendedWarnThresholdMs,
            'recommended_critical_threshold_ms' => $this->recommendedCriticalThresholdMs,
            'recommended_regions' => $this->recommendedRegions,
            'rationale' => $this->rationale,
            'stripped_citations' => $this->strippedCitations,
        ];
    }
}
