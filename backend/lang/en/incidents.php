<?php

// The composed incident titles, and the ONLY place each sentence is spelled.
// App\Services\Monitoring\IncidentTitle resolves these: once with the locale
// pinned to `en` for the stored `incidents.title` column, and once per reader
// for the operator app, the push and the public status page. Editing a value
// here therefore changes both renders at once, which is the point.
//
// `ssl_expiring` is deliberately absent as a key: the resolver always appends
// `_one` or `_other` from the `days` parameter, while the BARE `incidents.ssl_expiring`
// is what gets persisted and what the client enum matches. Laravel's
// trans_choice() is not used because the Flutter half has no plural API to
// mirror it with.
return [
    'monitor_down' => ':monitor is down',
    'metric_warn_bound' => ':metric breached warn bound',
    'metric_critical_bound' => ':metric breached critical bound',
    'metric_string_value' => ':metric reported ":value"',

    // The pair exists for the English alone: this is the one sentence this
    // change deliberately alters, because the writer it replaces said
    // "in 1 days".
    'ssl_expiring_one' => ':monitor SSL cert expires in :days day',
    'ssl_expiring_other' => ':monitor SSL cert expires in :days days',

    'ai_anomaly' => 'Anomaly detected on :monitor',
];
