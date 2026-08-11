<?php

namespace App\Support\Monitoring;

use App\Services\Ai\LaravelAiMetricDiscoveryGateway;
use App\Services\Ai\MetricDiscoveryService;

/**
 * Rounds a threshold bound to a fixed number of significant figures, so no
 * arithmetic behind a bound reaches the operator raw.
 *
 * It lives here rather than on either caller because there are two, on opposite
 * sides of one pipeline, and a bound can be born in either: the model supplies
 * one and {@see LaravelAiMetricDiscoveryGateway::resolveBounds()}
 * accepts it, or the model omits one and
 * {@see MetricDiscoveryService::boundsFor()} derives it from the
 * observed reading. A live run rendered "warn 6.096666666666667" for free disk
 * space, and both paths produce exactly that number: the model computing
 * 18.29 / 3, and the derivation dividing the same reading by the same
 * WARN_MULTIPLIER. One quantiser owning both is why fixing one path cannot leave
 * the other printing it.
 *
 * Significant figures rather than a fixed decimal count, because a bound is a
 * gigabyte on one metric and a millisecond on the next, and no single decimal
 * count reads well for both.
 */
class ThresholdQuantiser
{
    /**
     * The number of significant figures a bound is rounded to.
     */
    public const int SIGNIFICANT_FIGURES = 3;

    /**
     * `$value` rounded to {@see self::SIGNIFICANT_FIGURES}.
     *
     * Rounding is monotonic but NOT injective: two bounds inside one bucket
     * collapse onto a single value (1236 and 1237 both land on 1240). Every
     * caller therefore has to quantise BEFORE any direction check, or a pair the
     * ordering rule would refuse is emitted after the guard has already passed
     * it.
     */
    public static function quantise(float $value): float
    {
        if ($value === 0.0) {
            return 0.0;
        }

        $magnitude = floor(log10(abs($value)));
        $scale = 10 ** (self::SIGNIFICANT_FIGURES - 1 - $magnitude);

        return round($value * $scale) / $scale;
    }
}
