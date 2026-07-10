<?php

namespace App\Enums;

/**
 * Lifecycle state of an incident.
 *
 * detected -> investigating -> identified -> monitoring -> resolved.
 * `mitigated` is retained for back-compat with pre-redesign data.
 */
enum IncidentStatus: string
{
    case Detected = 'detected';
    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Mitigated = 'mitigated';
    case Resolved = 'resolved';

    /**
     * True while the incident still demands operator attention.
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * True when the lifecycle has reached a final state (nothing left
     * to do, no further updates expected).
     */
    public function isTerminal(): bool
    {
        return $this === self::Resolved;
    }
}
