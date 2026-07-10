<?php

namespace App\Enums;

/**
 * Which side of the numeric range counts as "bad" for a metric threshold.
 *
 *   - HighBad: higher values trigger warn/critical (e.g. latency).
 *   - LowBad:  lower values trigger warn/critical (e.g. free memory).
 */
enum ThresholdDirection: string
{
    case HighBad = 'high_bad';
    case LowBad = 'low_bad';

    /**
     * Check whether warn/critical bounds are ordered correctly for this
     * direction.
     *
     * HighBad requires warn < critical (warn fires earlier on the climb);
     * LowBad requires warn > critical (warn fires earlier on the descent).
     *
     * @param float $warn
     * @param float $critical
     *
     * @return bool
     */
    public function validateBounds(float $warn, float $critical): bool
    {
        return match ($this) {
            self::HighBad => $warn < $critical,
            self::LowBad => $warn > $critical,
        };
    }
}
