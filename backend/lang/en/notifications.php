<?php

// User-facing strings for the incident notification classes; the mail and
// database channels resolve these via __() so IncidentOpened/IncidentResolved
// render in the notifiable's preferred locale (see User::preferredLocale()).
// The OneSignal push channel resolves both `en` and `tr` explicitly since it
// carries a language map instead of following the app locale.
return [
    'incident_opened_subject' => '[Uptizm] :monitor is down',
    'incident_opened_greeting' => 'Incident opened',
    'incident_opened_state_line' => ':monitor has entered the ":lifecycle" state.',
    'incident_opened_title' => ':monitor is down',
    'incident_opened_push_heading' => ':monitor is down',

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
