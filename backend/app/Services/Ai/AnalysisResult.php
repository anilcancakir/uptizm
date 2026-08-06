<?php

namespace App\Services\Ai;

use App\Enums\LocationBasis;
use App\Enums\RegionBasis;
use App\Http\Controllers\Api\V1\MonitorController;

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
 *
 * `confidence` is a fourth field but not a classification: it is never in a
 * model's schema and never read from a model's answer. It is derived by
 * {@see MonitorController} from evidence quality (whether a model answered at
 * all, whether `region_basis` names a measured basis, and whether a body
 * digest existed) and attached via
 * {@see self::withConfidence()} after this object already exists, precisely
 * so a model narrating its own certainty can never influence what the
 * operator is told to trust.
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
     * @param  string  $confidence  How much evidence the suggestion actually rests on: `high`,
     *                              `medium` or `low`, matching the Dart `AiConfidence` enum's case
     *                              names exactly. NEVER self-reported by a model: it is derived by
     *                              {@see MonitorController} from evidence already in scope, via
     *                              {@see self::withConfidence()}, after this object is built. The
     *                              default here is `low` only because that is the honest answer
     *                              for an instance nobody has classified yet, not because it is
     *                              this class's own opinion.
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
        public string $confidence = 'low',
    ) {}

    /**
     * A copy carrying the given [$confidence], every other field unchanged.
     *
     * The only mutator this immutable object offers, because `confidence` is
     * the only field a caller ever needs to attach AFTER construction: it is
     * derived from evidence (whether a model answered, `region_basis`, and
     * whether a digest existed) that is only fully known once the controller
     * has already built or received the result. Rebuilds the object field by
     * field rather than `clone ... with`, which this codebase's PHP 8.4 floor
     * does not have.
     */
    public function withConfidence(string $confidence): self
    {
        return new self(
            recommendedIntervalSeconds: $this->recommendedIntervalSeconds,
            recommendedWarnThresholdMs: $this->recommendedWarnThresholdMs,
            recommendedCriticalThresholdMs: $this->recommendedCriticalThresholdMs,
            recommendedRegions: $this->recommendedRegions,
            rationale: $this->rationale,
            strippedCitations: $this->strippedCitations,
            serviceClass: $this->serviceClass,
            regionBasis: $this->regionBasis,
            recommendedSloTarget: $this->recommendedSloTarget,
            confidence: $confidence,
        );
    }

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
            'confidence' => $this->confidence,
        ];
    }
}
