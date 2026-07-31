<?php

namespace App\Services\Ai;

use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Enums\ThresholdDirection;
use App\Support\Monitoring\MetricCandidate;

/**
 * The immutable set of candidate selections the discovery LLM answered with,
 * after every field has been resolved against a real enum and every ref
 * range-checked against the catalog that was sent.
 *
 * Mirrors {@see AnalysisResult}'s role: a SUGGESTION, never a decision. Nothing
 * here creates a metric; the operator still submits the existing metric form.
 *
 * What this object deliberately does NOT carry is an extraction path. A
 * selection names a `ref` and the service resolves that ref back to the
 * {@see MetricCandidate} the backend generated itself, which is what keeps the
 * model structurally incapable of authoring an extraction rule.
 *
 * The selections are plain arrays rather than a second value object because a
 * companion class would have to live in its own file, and one shape used by one
 * producer and one consumer does not earn that.
 */
readonly class MetricDiscoveryResult
{
    /**
     * @param  list<array{
     *     ref: string,
     *     label: string,
     *     type: MetricType,
     *     unit: MetricUnit|null,
     *     thresholdDirection: ThresholdDirection|null,
     *     warnBound: float|null,
     *     criticalBound: float|null,
     * }>  $selections
     */
    public function __construct(
        public array $selections,
    ) {}
}
