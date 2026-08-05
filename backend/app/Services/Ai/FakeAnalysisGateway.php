<?php

namespace App\Services\Ai;

use App\Enums\MonitorRegion;
use Throwable;

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
 *
 * {@see self::throwing()} models the OTHER half of the contract: the real
 * gateway throws when the model's output cannot be trusted past its retry, and
 * the HTTP client throws when the provider cannot be reached. Both are the
 * caller's problem to degrade from, so the fake can raise either on demand
 * instead of every degrade test declaring its own anonymous gateway.
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
     * Raised in place of an answer while the fake is in throwing mode.
     */
    protected ?Throwable $failure = null;

    /**
     * A fake that raises [$failure] instead of suggesting anything.
     */
    public static function throwing(Throwable $failure): self
    {
        $fake = new self;
        $fake->failure = $failure;

        return $fake;
    }

    /**
     * Return a fixed, boundary-safe suggestion for any probe.
     *
     * @throws Throwable The failure {@see self::throwing()} was seeded with.
     */
    public function analyze(AnalysisPayload $payload): AnalysisResult
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

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
