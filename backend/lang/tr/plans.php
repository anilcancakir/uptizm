<?php

return [

    /*
     * Turkish puts the verb last, so the feature name opens the sentence and
     * `kullanılabilir` closes it. Do not glue a prefix onto these strings: the
     * placeholders are the only safe insertion points.
     */
    'upgrade_required' => ':feature :plan planı ve üzerinde kullanılabilir. Kullanmak için yükseltin.',

    'upgrade_required_metered' => 'Ücretsiz :count yapay zeka izleyici kurulumunun tamamını kullandınız. :feature :plan planı ve üzerinde kullanılabilir.',

    /*
     * Feature names, keyed by `GatedFeature` value. Turkish has no article, so
     * the English "The AI assistant" loses its "The" rather than gaining a
     * literal translation of it.
     */
    'features' => [
        'ai_incident_analysis' => 'Yapay zeka olay analizi',
        'ai_incident_drafting' => 'Yapay zeka ile olay taslağı',
        'ai_metric_discovery' => 'Yapay zeka metrik keşfi',
        'ai_monitor_analysis' => 'Yapay zeka izleyici analizi',
        'ai_assistant' => 'Yapay zeka asistanı',
        'ai_weekly_digest' => 'Yapay zeka haftalık özeti',
    ],

];
