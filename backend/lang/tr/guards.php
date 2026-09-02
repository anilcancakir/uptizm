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
            'no_embedded_credential' => ':Attribute, kullanıcı adı veya parola içeremez. Bunun yerine monitörün '
                .'kimlik doğrulama ayarlarını kullanın.',
        ],
    ],

    'publish' => [
        'terms_not_reviewed' => 'Yayımlanamaz: koşullar henüz incelenmedi.',
        'no_monitor_attached' => 'Yayımlanamaz: hiçbir monitör eklenmemiş.',
    ],

    'status_page' => [
        'subscriber_limit_reached' => 'Bu durum sayfası :limit abone sınırına ulaştı. Daha fazla eklemek için ekibin planını yükseltin.',
        'limit_reached_singular' => 'Planınız (:plan) en fazla :limit durum sayfası ile sınırlıdır. Daha fazla eklemek için yükseltin.',
        'limit_reached_plural' => 'Planınız (:plan) en fazla :limit durum sayfası ile sınırlıdır. Daha fazla eklemek için yükseltin.',
        'private_requires_business' => 'Özel durum sayfaları Business plan ve üzerinde kullanılabilir. Bir sayfayı '
            .'özel yapmak için yükseltin.',
    ],

    'team' => [
        'store_subscription_active' => 'Bu ekibi hâlâ bir mağaza aboneliği faturalandırıyor. Önce satın alındığı mağaza hesabından iptal edin: ekibi şimdi silmek planı kaldırır ve mağazanın sizi faturalandırmaya devam etmesine yol açar; bu uygulama bunu sizin adınıza iptal edemez.',
        'responder_limit_reached_singular' => 'Planınız (:plan) en fazla :limit yanıtlayıcı ile sınırlıdır. Daha fazla davet etmek için yükseltin.',
        'responder_limit_reached_plural' => 'Planınız (:plan) en fazla :limit yanıtlayıcı ile sınırlıdır. Daha fazla davet etmek için yükseltin.',
        'monitor_limit_reached' => 'Planınız (:plan) en fazla :limit monitör ile sınırlıdır. Daha fazla eklemek için yükseltin.',
        'check_interval_floor' => 'Planınız (:plan) en fazla :seconds aralıkla kontrol yapar. Daha hızlı kontroller için yükseltin.',
        'region_limit_reached_singular' => 'Planınız (:plan) her monitörü en fazla :limit bölgeden kontrol eder. Daha fazla eklemek için yükseltin.',
        'region_limit_reached_plural' => 'Planınız (:plan) her monitörü en fazla :limit bölgeden kontrol eder. Daha fazla eklemek için yükseltin.',
    ],

    'threshold' => [
        'critical_above_warning' => 'Yüksek değerler daha kötüyse kritik eşik, uyarı eşiğinin üzerinde olmalıdır.',
        'critical_below_warning' => 'Düşük değerler daha kötüyse kritik eşik, uyarı eşiğinin altında olmalıdır.',
    ],

    'metric' => [
        'header_credentials' => 'Bu yanıt başlığı kimlik bilgisi taşıdığı için metrik olarak kaydedilemez. Her '
            .'kontrolde değer düz metin olarak saklanır.',
        'duplicate_value' => 'Bir değer iki kez listelenmiş. Eşleştirme büyük/küçük harfi yok sayar ve baştaki/sondaki '
            .'boşlukları kırpar; bu yüzden ikinci girdi, birincinin zaten kapsadığından başka hiçbir şeyi bantlayamaz.',
        'blank_value' => 'Bir değer boş olamaz. Eşleştirme baştaki/sondaki boşlukları kırptığı için bu, her boş '
            .'okumayla eşleşir.',
        'overlapping_band' => '":value" birden fazla bantta tanımlanmış. Eşleştirme büyük/küçük harfi ve boşlukları '
            .'yok sayar; bu yüzden bir değer yalnızca bir listede yer alabilir.',
        'unmatched_band_needs_list' => 'Eşleşmeyen değerler için bir bant seçmeden önce en az bir sağlıklı, uyarı '
            .'veya kritik değer ekleyin.',
    ],

];
