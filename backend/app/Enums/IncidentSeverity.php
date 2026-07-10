<?php

namespace App\Enums;

/**
 * Severity tier assigned to an incident for routing and tone styling.
 *
 * Mirrors the Flutter palette: critical -> red, warn -> amber, info -> blue.
 */
enum IncidentSeverity: string
{
    case Critical = 'critical';
    case Warn = 'warn';
    case Info = 'info';

    /**
     * Customer-facing impact tier implied by this operator severity.
     *
     * Used when a manual incident is opened without an explicit
     * `impact` — the operator's severity choice is the strongest
     * signal we have about what customers are actually seeing.
     */
    public function toImpact(): IncidentImpact
    {
        return match ($this) {
            self::Critical => IncidentImpact::Critical,
            self::Warn => IncidentImpact::Major,
            self::Info => IncidentImpact::Minor,
        };
    }

    /**
     * Component-status bucket implied by this operator severity.
     *
     * Seeded onto the affected-monitor pivot at incident open so the
     * public banner rolls up to match the operator's severity choice
     * instead of trailing the monitor's raw probe health.
     */
    public function toComponentStatus(): ComponentStatus
    {
        return match ($this) {
            self::Critical => ComponentStatus::MajorOutage,
            self::Warn => ComponentStatus::PartialOutage,
            self::Info => ComponentStatus::DegradedPerformance,
        };
    }
}
