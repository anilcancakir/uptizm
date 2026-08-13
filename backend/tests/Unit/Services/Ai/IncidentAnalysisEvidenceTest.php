<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\IncidentAnalysisService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The two pure functions behind the incident-analysis body evidence, both
 * reachable only through {@see IncidentAnalysisService::bodyEvidence()} and both
 * worth pinning on their own: one decides what a reader is shown, the other
 * decides what is hidden.
 */
class IncidentAnalysisEvidenceTest extends TestCase
{
    public function test_the_slice_does_not_reach_into_a_sibling_that_merely_shares_a_prefix(): void
    {
        // Raised in review. A prefix test on a dot path is not a subtree test:
        // `checks.cache` is a prefix of `checks.cache2.details.latency_ms`, so a
        // neighbouring component's fields would land in the slice as if they
        // belonged to the metric under investigation, and the analyser would
        // reason about the wrong subsystem while looking at correctly-labelled
        // evidence.
        // The metric path is deliberately three segments deep, so the parent is
        // `checks.cache` and the sibling `checks.cache2` shares it as a raw
        // string prefix. A four-segment path would make this pass without the
        // fix, because `checks.cache.details` is not a prefix of anything under
        // `checks.cache2`: the first version of this test did exactly that and
        // was green against the defect.
        $fields = [
            'checks.cache.latency_ms' => '0.58',
            'checks.cache.store' => 'redis',
            'checks.cache2.latency_ms' => '99.0',
            'checks.cachexyz' => 'unrelated',
        ];

        $slice = $this->slice($fields, 'checks.cache.latency_ms');

        $this->assertArrayHasKey('checks.cache.latency_ms', $slice);
        $this->assertArrayHasKey('checks.cache.store', $slice);
        $this->assertArrayNotHasKey('checks.cache2.latency_ms', $slice);
        $this->assertArrayNotHasKey('checks.cachexyz', $slice);
    }

    public function test_a_verdict_still_reaches_the_slice_from_anywhere_in_the_body(): void
    {
        // The boundary fix must not cost the verdicts, which are deliberately
        // collected from the whole body rather than from the metric's subtree.
        $slice = $this->slice([
            'status' => 'degraded',
            'checks.storage.status' => 'degraded',
            'checks.cache.details.latency_ms' => '0.58',
        ], 'checks.cache.details.latency_ms');

        $this->assertArrayHasKey('status', $slice);
        $this->assertArrayHasKey('checks.storage.status', $slice);
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    protected function slice(array $fields, ?string $metricPath): array
    {
        $method = new ReflectionMethod(IncidentAnalysisService::class, 'slice');

        return $method->invoke(app(IncidentAnalysisService::class), $fields, $metricPath);
    }
}
