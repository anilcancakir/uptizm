<?php

// Every visitor-facing string on the PUBLIC status-page surface: the page
// itself, the three subscribe result pages, and the two subscriber emails.
//
// The page renders in ONE language, the one its owner set in
// `status_pages.locale`; `ShowStatusPageController` applies it per request and
// nothing here negotiates. So a value edited in this file changes the English
// page, and the same key in `lang/tr/status.php` changes the Turkish one.
//
// `Powered by Uptizm` is deliberately absent: it is a brand line, not copy, and
// `resources/views/status/partials/footer.blade.php` carries it untranslated on
// purpose.
//
// Two conventions in here are load-bearing rather than stylistic:
//
//   - A key named `*_before_page` / `*_after_page` is a sentence that a Blade
//     splits around a `<strong>{{ $pageName }}</strong>` it must keep. The page
//     name cannot travel as a `:page` parameter there, because emitting markup
//     through a parameter needs `{!! !!}` and no status Blade may carry one
//     (`title_params` can contain a monitored response body). English puts the
//     name at the end of its clause and Turkish at the head, which is why the
//     two languages use two different keys rather than one with a placeholder.
//   - `incidents.postmortem_published_before` / `_after` bracket a `<time>`
//     element for the same reason, and one of the pair is empty in each locale
//     because the two languages put the verb on opposite sides of the stamp.
return [
    /*
     * The five banner labels, one per rung of the severity ladder plus the
     * no-verdict state. Composed in `StatusPageAssembler::overallLabel()`, which
     * is the largest copy on the page and the one a template grep misses.
     */
    'banner' => [
        'operational' => 'All Systems Operational',
        'degraded' => 'Degraded Performance',
        'partial_outage' => 'Partial System Outage',
        'major_outage' => 'Major System Outage',
        'unknown' => 'No Components Published',
        'updated' => 'updated :ago',
    ],

    'components' => [
        'heading' => 'Components',
        'empty' => 'No components are currently published on this page.',
    ],

    'incidents' => [
        'heading' => 'Incidents',
        'empty' => 'No incidents reported.',
        'started' => 'started :at',

        /*
         * A DATE FORMAT, not a sentence: the day heading each incident group
         * carries. It is a catalogue entry because month-name order is part of
         * the language ("August 11, 2026" against "11 Ağustos 2026"), and it is
         * the only date on the page that is not inside a `<time>` element, so it
         * is the only one the layout's script never rewrites into the viewer's
         * own locale.
         */
        'day_format' => 'F j, Y',
        'postmortem' => 'Postmortem',
        'postmortem_published_before' => 'published',
        'postmortem_published_after' => '',

        /*
         * The per-update status prefix ("Resolved - ", "Investigating - ").
         * These mirror `lib/app/enums/incident_lifecycle.dart`'s client labels so
         * an operator and their customer read the same word for one state.
         */
        'update_status' => [
            'detected' => 'Detected',
            'investigating' => 'Investigating',
            'identified' => 'Identified',
            'monitoring' => 'Monitoring',
            'mitigated' => 'Mitigated',
            'resolved' => 'Resolved',
        ],

        /*
         * The badge beside an incident's title. Lower case and a separate entry
         * from `update_status` on purpose: the template humanised the raw
         * lifecycle value (`str_replace('_', ' ', ...)`) rather than printing a
         * machine token, so these ARE copy, and the English values reproduce that
         * humanised form exactly so the English page does not shift a byte.
         */
        'lifecycle_badge' => [
            'detected' => 'detected',
            'investigating' => 'investigating',
            'identified' => 'identified',
            'monitoring' => 'monitoring',
            'mitigated' => 'mitigated',
            'resolved' => 'resolved',
        ],
    ],

    'maintenances' => [
        'heading' => 'Scheduled maintenance',
        'state' => [
            'in_progress' => 'in progress',
            'scheduled' => 'scheduled',
        ],
        // Between the two bounds of one window, so it reads as a range rather
        // than as two unrelated timestamps.
        'range_separator' => 'to',
    ],

    'subscribe' => [
        'heading' => 'Subscribe to updates',
        'body' => 'Get an email when :page posts a new incident.',
        'action' => 'Subscribe',
    ],

    'confirmed' => [
        'title' => 'Subscription confirmed',
        'heading' => 'You are subscribed',
        'body' => 'You will now receive incident updates for :page. Every email includes a one-click unsubscribe link.',
    ],

    'check_inbox' => [
        // One key for the `<title>` and the `<h1>`: they are the same sentence,
        // and a second key is how they end up disagreeing.
        'title' => 'Check your inbox',
        'body' => 'If the address you entered can subscribe to :page, a confirmation email is on its way. Click the link inside to finish subscribing.',
    ],

    'unsubscribed' => [
        'title' => 'Unsubscribed',
        'heading' => 'You are unsubscribed',
        'body' => 'You will no longer receive incident updates. You can subscribe again any time from the status page.',
    ],

    /*
     * The two subscriber emails. Their SUBJECTS are composed in
     * `App\Mail\StatusPageSubscribeConfirmation` and
     * `App\Mail\ScheduledMaintenanceAnnounced` and are still English; nothing
     * sets a locale on the send path either, so today these bodies resolve in
     * the deployment default. The keys exist so the copy has one home when a
     * later step gives the send path a language.
     */
    'emails' => [
        'confirm' => [
            'heading' => 'Confirm your subscription',
            'intro_before_page' => 'You asked to receive incident updates for',
            'instruction' => 'Confirm your email address to start receiving them:',
            'action' => 'Confirm subscription',
            'ignore' => 'If you did not request this, you can ignore this email and nothing will happen.',
        ],
        'maintenance' => [
            'heading' => 'Scheduled maintenance',
            'intro_after_page' => 'has planned maintenance coming up.',
            'components' => 'Affected components:',
            'footer' => 'You are receiving this because you confirmed a subscription to :page.',
            'unsubscribe' => 'Unsubscribe',
        ],
    ],
];
