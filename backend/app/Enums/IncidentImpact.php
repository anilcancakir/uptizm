<?php

namespace App\Enums;

/**
 * Customer-facing impact level of an incident.
 *
 * Distinct from {@see IncidentSeverity} (operator tier), and the only
 * one of the two that reaches a customer: the public status page
 * serializes `impact` and never `severity`.
 *
 * Always derived from the incident's severity via
 * {@see IncidentSeverity::toImpact()}, at open and again on every
 * escalation. There is no operator override; a docblock here used to
 * describe an `impact_override` flag that was never built, and reading
 * it as a real seam is how the escalation path came to leave `impact`
 * alone in the first place.
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
