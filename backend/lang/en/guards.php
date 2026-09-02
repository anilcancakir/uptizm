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
            'no_embedded_credential' => 'The :attribute must not embed a username or password. '
                ."Use the monitor's authentication settings instead.",
        ],
    ],

    'publish' => [
        'terms_not_reviewed' => 'Cannot publish: terms have not been reviewed.',
        'no_monitor_attached' => 'Cannot publish: no monitor is attached.',
    ],

    'status_page' => [
        'subscriber_limit_reached' => "This status page has reached its :limit-subscriber limit. Upgrade the team's plan to add more.",
        'limit_reached_singular' => 'Your :plan plan is limited to :limit status page. Upgrade to add more.',
        'limit_reached_plural' => 'Your :plan plan is limited to :limit status pages. Upgrade to add more.',
        'private_requires_business' => 'Private status pages are available on the Business plan and up. '
            .'Upgrade to make a page private.',
    ],

    'team' => [
        'store_subscription_active' => 'A store subscription is still billing this team. Cancel it in the store account that bought it first: deleting the team now would remove the plan and leave the store charging you, and this app cannot cancel it for you.',
        'responder_limit_reached_singular' => 'Your :plan plan is limited to :limit responder. Upgrade to invite more.',
        'responder_limit_reached_plural' => 'Your :plan plan is limited to :limit responders. Upgrade to invite more.',
        'monitor_limit_reached' => 'Your :plan plan is limited to :limit monitors. Upgrade to add more.',
        'check_interval_floor' => 'Your :plan plan checks at most every :seconds. Upgrade for faster checks.',
        'region_limit_reached_singular' => 'Your :plan plan checks from at most :limit region per monitor. Upgrade to add more.',
        'region_limit_reached_plural' => 'Your :plan plan checks from at most :limit regions per monitor. Upgrade to add more.',
    ],

    'threshold' => [
        'critical_above_warning' => 'Critical must be above the warning bound when higher values are worse.',
        'critical_below_warning' => 'Critical must be below the warning bound when lower values are worse.',
    ],

    'metric' => [
        'header_credentials' => 'This response header carries credentials, so it cannot be recorded as a metric. '
            .'Every check would persist the value in cleartext.',
        'duplicate_value' => 'A value is listed twice. Matching folds case and trims surrounding whitespace, so '
            .'the second entry can never band anything the first does not already claim.',
        'blank_value' => 'A value cannot be blank. Matching trims surrounding whitespace, so this would match '
            .'every empty reading.',
        'overlapping_band' => '":value" is configured in more than one band. Matching ignores case and surrounding '
            .'whitespace, so a value may appear in one list only.',
        'unmatched_band_needs_list' => 'Add at least one healthy, warning or critical value before choosing a band '
            .'for unmatched values.',
    ],

];
