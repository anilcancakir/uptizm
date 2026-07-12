<?php

namespace App\Services\Ai;

use App\Enums\MonitorRegion;

/**
 * A deterministic monitor-setup analysis gateway for tests and offline runs.
 *
 * Bound in place of {@see LaravelAiAnalysisGateway} so no real Anthropic call
 * is ever made in CI. It returns a fixed {@see AnalysisResult} independent of
 * the payload contents, so assertions stay byte-stable.
 *
 * It still honors the boundary contract: it only SUGGESTS, it never decides
 * for the operator, and its rationale cites only the region every payload's
 * probe actually ran from.
 */
class FakeAnalysisGateway implements AnalysisGateway
{
    /**
     * Default suggested check interval, in seconds.
     */
    private const DEFAULT_INTERVAL_SECONDS = 60;

    /**
     * Default suggested warn-severity response-time bound, in milliseconds.
     */
    private const DEFAULT_WARN_THRESHOLD_MS = 800;

    /**
     * Default suggested critical-severity response-time bound, in milliseconds.
     */
    private const DEFAULT_CRITICAL_THRESHOLD_MS = 2000;

    /**
     * Return a fixed, boundary-safe suggestion for any probe.
     */
    public function analyze(AnalysisPayload $payload): AnalysisResult
    {
        return new AnalysisResult(
            recommendedIntervalSeconds: self::DEFAULT_INTERVAL_SECONDS,
            recommendedWarnThresholdMs: self::DEFAULT_WARN_THRESHOLD_MS,
            recommendedCriticalThresholdMs: self::DEFAULT_CRITICAL_THRESHOLD_MS,
            recommendedRegions: [
                MonitorRegion::USEast->value,
            ],
            rationale: 'Deterministic analysis stub: baseline suggestion from the exploratory probe.',
            strippedCitations: [],
        );
    }
}
