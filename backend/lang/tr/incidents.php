<?php

// Turkish translation of lang/en/incidents.php; keep every ":placeholder"
// token and key path identical to the English source.
//
// `monitor_down` carries the same sentence as `notifications.incident_opened_title`
// on purpose: the push heading and the push body sit two lines apart, so a
// second wording would read as two different outages.
return [
    'monitor_down' => ':monitor kesintide',
    'metric_warn_bound' => ':metric uyarı sınırını aştı',
    'metric_critical_bound' => ':metric kritik sınırını aştı',
    'metric_string_value' => ':metric ":value" bildirdi',

    // Both entries carry the same sentence: Turkish keeps the noun singular
    // after a cardinal, so "1 gün" and "14 gün" inflect nothing. The pair still
    // exists on this side because the resolver picks a suffixed key for every
    // locale, and a missing entry would render its own dotted name.
    'ssl_expiring_one' => ':monitor sertifikası :days gün içinde doluyor',
    'ssl_expiring_other' => ':monitor sertifikası :days gün içinde doluyor',

    'ai_anomaly' => ':monitor üzerinde anomali saptandı',
];
