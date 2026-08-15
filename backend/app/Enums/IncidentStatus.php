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

    /**
     * The terminal states, as wire values, for a query that has to ask this
     * question in SQL.
     *
     * Derived from {@see self::isTerminal()} rather than listed, so the
     * predicate has one definition and a new terminal case cannot be added to
     * one of them and forgotten in the other. It exists because "is this
     * incident still open" was previously only answerable in PHP, which meant
     * every caller loaded a monitor's whole incident history to find the one
     * row it wanted.
     *
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isTerminal()),
        ));
    }
}
