<?php

// Turkish translations of the hand-written validation messages raised by the
// application's own guards (HostGuard's SSRF checks, status-page publish
// preconditions, plan-limit refusals, monitor-metric threshold ordering).
// Kept separate from validation.php because that file is Laravel's published
// defaults and a future `php artisan lang:publish --force` would overwrite
// anything added there; these keys belong to this app, not to the framework.
return [

    'host' => [
        // "host" rather than "host adresi": the guard accepts a name or an IP
        // literal, and a host is the name itself rather than an address.
        'no_host' => 'Url geçerli bir host içermelidir.',
        'not_allowed' => "Url'deki host'a izin verilmiyor.",
        'malformed' => 'Url biçimi geçersiz.',
        'https_required' => 'Url https şeması kullanmalıdır.',
        'no_credentials_or_port' => 'Url, kimlik bilgisi veya port içermemelidir.',
        'unresolvable' => "Url'deki host çözümlenemedi.",

        // The same refusals raised from a rule closure through $fail(), where
        // the field being validated is not always called "url". :attribute is
        // substituted by the validator AFTER the translator runs, so it has to
        // survive into this file untouched.
        'field' => [
            'no_host' => ':Attribute geçerli bir host içermelidir.',
            'not_allowed' => ':Attribute alanındaki host\'a izin verilmiyor.',
            'port_range' => ':Attribute alanındaki port 1 ile 65535 arasında olmalıdır.',
        ],
    ],

    'publish' => [
        'terms_not_reviewed' => 'Yayımlanamaz: koşullar henüz incelenmedi.',
        'no_monitor_attached' => 'Yayımlanamaz: hiçbir monitör eklenmemiş.',
    ],

    'status_page' => [
        'subscriber_limit_reached' => 'Bu durum sayfası :limit abone sınırına ulaştı. Daha fazla eklemek için ekibin planını yükseltin.',
    ],

    'team' => [
        'store_subscription_active' => 'Bu ekibi hâlâ bir mağaza aboneliği faturalandırıyor. Önce satın alındığı mağaza hesabından iptal edin: ekibi şimdi silmek planı kaldırır ve mağazanın sizi faturalandırmaya devam etmesine yol açar; bu uygulama bunu sizin adınıza iptal edemez.',
        'responder_limit_reached_singular' => 'Planınız (:plan) en fazla :limit yanıtlayıcı ile sınırlıdır. Daha fazla davet etmek için yükseltin.',
        'responder_limit_reached_plural' => 'Planınız (:plan) en fazla :limit yanıtlayıcı ile sınırlıdır. Daha fazla davet etmek için yükseltin.',
    ],

    'threshold' => [
        'critical_above_warning' => 'Yüksek değerler daha kötüyse kritik eşik, uyarı eşiğinin üzerinde olmalıdır.',
        'critical_below_warning' => 'Düşük değerler daha kötüyse kritik eşik, uyarı eşiğinin altında olmalıdır.',
    ],

];
