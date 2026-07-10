<?php

namespace App\Enums;

/**
 * Health bucket exposed on the public status page for each monitor
 * acting as a component. Mirrors Atlassian Statuspage's taxonomy
 * minus the scheduled-maintenance state.
 */
enum ComponentStatus: string
{
    case Operational = 'operational';
    case DegradedPerformance = 'degraded_performance';
    case PartialOutage = 'partial_outage';
    case MajorOutage = 'major_outage';

    /**
     * Monotonic weight for worst-case rollups when a parent group needs
     * to reflect the unhealthiest child.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Operational => 0,
            self::DegradedPerformance => 1,
            self::PartialOutage => 2,
            self::MajorOutage => 3,
        };
    }

    /**
     * Derive the impact level this component status maps to when it
     * drives a rolled-up incident impact.
     */
    public function toImpact(): IncidentImpact
    {
        return match ($this) {
            self::Operational => IncidentImpact::None,
            self::DegradedPerformance => IncidentImpact::Minor,
            self::PartialOutage => IncidentImpact::Major,
            self::MajorOutage => IncidentImpact::Critical,
        };
    }
}
