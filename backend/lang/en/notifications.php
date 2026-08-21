<?php

// User-facing strings for the incident notification classes; the mail and
// database channels resolve these via __() so IncidentOpened/IncidentResolved
// render in the notifiable's preferred locale (see User::preferredLocale()).
// The OneSignal push channel resolves both `en` and `tr` explicitly since it
// carries a language map instead of following the app locale.
return [
    // `:title` rather than ":monitor is down", because most incidents are not a
    // monitor being down. A metric-bound breach, an AI anomaly, an expiring
    // certificate and a hand-filed incident all opened a page that said the
    // service was down while it was answering 200s: measured in the bell on a
    // healthy monitor, "API is down" over a body reading "HTTP status code
    // breached critical bound". `:title` is the incident's own composed title,
    // which for a real down incident IS ":monitor is down" (the same sentence
    // out of `incidents.monitor_down`), so nothing changes for that case.
    // `:monitor` stays available to every key here and is what the state line
    // below uses, so the monitor is never lost from the mail.
    'incident_opened_subject' => '[Uptizm] :title',
    'incident_opened_greeting' => 'Incident opened',
    'incident_opened_state_line' => ':monitor has entered the ":lifecycle" state.',
    'incident_opened_title' => ':title',
    'incident_opened_push_heading' => ':title',

    // The escalation copy never says "opened": the operator has been looking at
    // this incident already, and a second open-shaped page reads as a separate
    // outage.
    'incident_escalated_subject' => '[Uptizm] :monitor got worse',
    'incident_escalated_greeting' => 'Incident escalated',
    'incident_escalated_state_line' => ':monitor is now more severe and is in the ":lifecycle" state.',
    'incident_escalated_title' => ':monitor got worse',
    'incident_escalated_push_heading' => ':monitor got worse',

    'incident_resolved_subject' => '[Uptizm] :monitor is resolved',
    'incident_resolved_greeting' => 'Incident resolved',
    'incident_resolved_line' => 'The incident affecting :monitor has been resolved.',
    'incident_resolved_title' => ':monitor is resolved',
    'incident_resolved_push_heading' => ':monitor is resolved',

    'severity_line' => 'Severity: :severity.',
    'view_incident_action' => 'View incident',
    'unnamed_monitor' => 'A monitor',
];
