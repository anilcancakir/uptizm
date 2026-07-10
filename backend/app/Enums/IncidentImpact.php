<?php

namespace App\Enums;

/**
 * Customer-facing impact level of an incident.
 *
 * Distinct from {@see IncidentSeverity} (operator tier). Impact is
 * derived from the worst affected component status unless the
 * incident's `impact_override` flag is set, in which case operators
 * pin a manual value.
 */
enum IncidentImpact: string
{
    case None = 'none';
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';

    /**
     * Monotonic weight for worst-case rollup comparisons.
     */
    public function weight(): int
    {
        return match ($this) {
            self::None => 0,
            self::Minor => 1,
            self::Major => 2,
            self::Critical => 3,
        };
    }
}
