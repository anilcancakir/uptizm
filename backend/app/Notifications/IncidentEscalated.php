<?php

namespace App\Notifications;

use App\Services\Monitoring\ThresholdEvaluator;

/**
 * Notification sent when an OPEN incident's severity is raised, not when a new
 * incident opens.
 *
 * A two-tier metric is the natural shape of a health endpoint: `degraded` warns,
 * `down` pages. {@see ThresholdEvaluator} raises the
 * open incident's severity rather than opening a second one, so the timeline of a
 * single outage stays in one place, and this is what tells everybody about it.
 *
 * ## Why it is its own class and not a flag on {@see IncidentOpened}
 *
 * The copy has to be different. "Checkout API is down" as a fresh page, sent
 * about an incident an operator has been watching at warn for ten minutes, reads
 * as a second unrelated outage. Its own class also gives the Flutter feed its own
 * `data.type` and the operator their own notification-preference row, so muting
 * incident-opened noise does not also mute the escalation that matters more.
 *
 * ## Why it carries no channel logic
 *
 * Everything about WHERE a notification goes (the channel resolution, the
 * credential gates, the SMS opt-in, the disabled-channel filtering) is
 * severity-agnostic and lives in the parent. Only the event token differs, and
 * the parent reads that through {@see IncidentOpened::eventType()}. Duplicating
 * 480 lines to change one string would mean every future channel type had to be
 * added twice, and the exhaustive channel match exists precisely so that adding
 * one is loud.
 */
class IncidentEscalated extends IncidentOpened
{
    /**
     * {@inheritDoc}
     */
    protected function eventType(): string
    {
        return 'incident_escalated';
    }
}
