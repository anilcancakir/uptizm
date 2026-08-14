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
     * How loud this severity is, for comparing two of them.
     *
     * Higher outranks lower. The enum's declaration order already reads
     * critical-first, but `IncidentSeverity::cases()` order is not a contract to
     * hang alerting on: a reorder for cosmetic reasons would silently invert
     * which breach escalates which incident.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Warn => 2,
            self::Info => 1,
        };
    }

    /**
     * Whether this severity is louder than [$other], which is the question
     * behind escalating an already-open incident.
     */
    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /**
     * Customer-facing impact tier implied by this operator severity.
     *
     * Every incident goes through here, not only the manual ones this once
     * claimed to serve: `ThresholdEvaluator::createIncident()` and
     * `AiIncidentOpener` both derive `impact` from it, and between them they open
     * nearly every incident in the product. Both are named in backticks rather
     * than through `{@see}` because Pint's `fully_qualified_strict_types` fixer
     * would turn either FQCN into a real import, and an enum in the domain
     * vocabulary should not have to import two services to document itself.
     *
     * Warn maps to Minor rather than Major, and the rung it skips is the whole
     * point. The automatic path emits only Critical and Warn, so Warn is the
     * quieter of the only two tiers a machine ever picks; sending it to the
     * second-loudest CUSTOMER tier made the ladder two rungs tall in practice,
     * and the client collapses `critical` and `major` into one red badge
     * (`impactFromWire()` in `lib/app/enums/incident_impact.dart`). A metric
     * reading `degraded` on a monitor still answering HTTP 200 therefore read
     * exactly like a total outage, on the dashboard and on the public status
     * page both. Minor is amber, which is what a warning is.
     *
     * Major is not orphaned by that: it still arrives from
     * {@see ComponentStatus::PartialOutage} and from third-party status feeds
     * through `StatuspageV2Adapter`.
     */
    public function toImpact(): IncidentImpact
    {
        return match ($this) {
            self::Critical => IncidentImpact::Critical,
            self::Warn => IncidentImpact::Minor,
            self::Info => IncidentImpact::Minor,
        };
    }
}
