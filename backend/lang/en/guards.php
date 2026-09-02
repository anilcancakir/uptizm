<?php

// Hand-written validation messages raised by the application's own guards
// (HostGuard's SSRF checks, status-page publish preconditions, plan-limit
// refusals, monitor-metric threshold ordering). Kept separate from
// validation.php because that file is Laravel's published defaults and a
// future `php artisan lang:publish --force` would overwrite anything added
// there; these keys belong to this app, not to the framework.
return [

    'host' => [
        'no_host' => 'The url must contain a valid host.',
        'not_allowed' => 'The url host is not allowed.',
        'malformed' => 'The url is malformed.',
        'https_required' => 'The url must use the https scheme.',
        'no_credentials_or_port' => 'The url must not contain credentials or a port.',
        'unresolvable' => 'The url host could not be resolved.',

        // The same refusals raised from a rule closure through $fail(), where
        // the field being validated is not always called "url" (a monitor
        // carries one on `url`, a metric on `probe_url`). These name it with
        // :attribute, which the validator substitutes AFTER the translator has
        // run, so the placeholder has to survive into every locale.
        'field' => [
            'no_host' => 'The :attribute must contain a valid host.',
            'not_allowed' => 'The :attribute host is not allowed.',
            'port_range' => 'The :attribute port must be between 1 and 65535.',
        ],
    ],

    'publish' => [
        'terms_not_reviewed' => 'Cannot publish: terms have not been reviewed.',
        'no_monitor_attached' => 'Cannot publish: no monitor is attached.',
    ],

    'status_page' => [
        'subscriber_limit_reached' => "This status page has reached its :limit-subscriber limit. Upgrade the team's plan to add more.",
    ],

    'team' => [
        'store_subscription_active' => 'A store subscription is still billing this team. Cancel it in the store account that bought it first: deleting the team now would remove the plan and leave the store charging you, and this app cannot cancel it for you.',
        'responder_limit_reached_singular' => 'Your :plan plan is limited to :limit responder. Upgrade to invite more.',
        'responder_limit_reached_plural' => 'Your :plan plan is limited to :limit responders. Upgrade to invite more.',
    ],

    'threshold' => [
        'critical_above_warning' => 'Critical must be above the warning bound when higher values are worse.',
        'critical_below_warning' => 'Critical must be below the warning bound when lower values are worse.',
    ],

];
