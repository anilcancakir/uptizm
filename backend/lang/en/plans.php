<?php

return [

    /*
     * The sentence a plan gate refuses with.
     *
     * It reaches the operator three ways: inline as an `MSUpgradeNudge` on the
     * incident-analysis card and the weekly digest, and as the upgrade DIALOG
     * magic_starter raises from a controller's non-2xx branch. All three render
     * the server's `message` verbatim, which is why it lives here rather than in
     * the client: one translation covers every surface, including the one the
     * app cannot reach because the widget belongs to the starter package.
     *
     * `:feature` is a `GatedFeature` name and `:plan` a capitalized plan id.
     */
    'upgrade_required' => ':feature is available on the :plan plan and up. Upgrade to use it.',

    /*
     * The same refusal for a METERED allowance rather than a tier the team never
     * had. Says what was spent, because "available on Pro" alone would read as
     * though the free trials never existed.
     */
    'upgrade_required_metered' => 'You have used all :count free AI monitor setups. :feature is available on the :plan plan and up.',

    /*
     * Feature names, keyed by `GatedFeature` value. Each one is the SUBJECT of
     * the sentences above, so it is capitalized as a sentence opener and carries
     * its article where English wants one.
     */
    'features' => [
        'ai_incident_analysis' => 'AI incident analysis',
        'ai_incident_drafting' => 'AI incident drafting',
        'ai_metric_discovery' => 'AI metric discovery',
        'ai_monitor_analysis' => 'AI monitor analysis',
        'ai_assistant' => 'The AI assistant',
        'ai_weekly_digest' => 'The AI weekly digest',
    ],

];
