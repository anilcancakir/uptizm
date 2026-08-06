<?php

namespace App\Services\Ai;

use App\Enums\LocationBasis;
use App\Enums\RegionBasis;

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
 *
 * The three classification fields are closed sets rather than free text.
 * {@see LaravelAiAnalysisGateway} owns two of the catalogs, because it is the
 * class that puts them in the schema and refuses an answer outside them:
 * {@see LaravelAiAnalysisGateway::SERVICE_CLASSES} and
 * {@see LaravelAiAnalysisGateway::SLO_TARGETS}. The third is the
 * {@see RegionBasis} enum, which stands alone only because it is easy to
 * mistake for {@see LocationBasis} and that distinction needed one home; the
 * gateway schemas and refuses it exactly as it does the other two. All three
 * default to the honest, uninformative member of their set, so a caller that
 * never ran a model (the deterministic fallback, the fake) says "I do not
 * know" rather than accidentally asserting a classification.
 */
readonly class AnalysisResult
{
    /**
     * @param  int  $recommendedIntervalSeconds  The suggested check interval, in seconds.
     * @param  int  $recommendedWarnThresholdMs  The suggested warn-severity response-time bound, in milliseconds.
     * @param  int  $recommendedCriticalThresholdMs  The suggested critical-severity response-time bound, in milliseconds.
     * @param  list<string>  $recommendedRegions  The suggested relay regions to probe from.
     * @param  string  $rationale  The allowlist-cleaned narration behind the suggestion.
     * @param  list<string>  $strippedCitations  Out-of-catalog citations removed from the rationale.
     * @param  string  $serviceClass  What kind of service the target was read to be.
     * @param  string  $regionBasis  WHY these regions were suggested, which is a different
     *                               question from what a lookup achieved
     *                               ({@see LocationBasis}).
     * @param  string  $recommendedSloTarget  One of the three uptime targets the client
     *                                        offers, or `none` when a single probe does not
     *                                        justify committing to one.
     */
    public function __construct(
        public int $recommendedIntervalSeconds,
        public int $recommendedWarnThresholdMs,
        public int $recommendedCriticalThresholdMs,
        public array $recommendedRegions,
        public string $rationale,
        public array $strippedCitations = [],
        public string $serviceClass = 'unknown',
        public string $regionBasis = 'default',
        public string $recommendedSloTarget = 'none',
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
            'service_class' => $this->serviceClass,
            'region_basis' => $this->regionBasis,
            'recommended_slo_target' => $this->recommendedSloTarget,
        ];
    }
}
