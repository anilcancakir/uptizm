<?php

// Turkish translation of lang/en/status.php; keep every ":placeholder" token and
// key path identical to the English source.
//
// The wording deliberately mirrors the vocabulary the Flutter app already ships
// in `assets/lang/tr.json` (`uptizm.status.*`): "Tüm sistemler çalışıyor",
// "Performans düşük", "Büyük kesinti", "Bileşenler", "Güncellemelere abone ol".
// An operator configuring the page and the customer reading it should meet the
// same words, and the in-app preview of this very page is where the operator
// meets them first.
//
// `Powered by Uptizm` has no key here either: the footer keeps the brand line in
// English on every locale.
return [
    'language' => [
        'switcher' => 'Dil',
        'offer' => 'Bu sayfa :language olarak da mevcut.',
        'offer_action' => ':language sayfasına geç',
    ],

    'translation' => [
        // "dilinden", not the ablative on the language name itself: Turkish
        // vowel harmony makes that suffix differ per name (İngilizce -> -den,
        // Almanca -> -dan), and `:language` arrives from ICU, so the sentence
        // has to stay correct for a language nobody wrote copy for.
        'translated_from' => ':language dilinden otomatik çevrildi.',
        'translated' => 'Otomatik çevrildi.',
        'show_original' => 'Orijinalini göster',
        'pending' => 'Çeviri sürüyor.',

        // "yapılamadı", not "bekleniyor": the attempt happened and was refused.
        'unavailable' => 'Çeviri yapılamadı.',
    ],

    'theme' => [
        'label' => 'Tema',
        'system' => 'Sistem',
        'light' => 'Açık',
        'dark' => 'Koyu',
    ],

    'banner' => [
        'operational' => 'Tüm sistemler çalışıyor',
        'degraded' => 'Performans düşük',
        'partial_outage' => 'Kısmi kesinti',
        'major_outage' => 'Büyük kesinti',
        'unknown' => 'Yayınlanmış bileşen yok',
        'updated' => ':ago güncellendi',
    ],

    'components' => [
        'heading' => 'Bileşenler',
        'empty' => 'Bu sayfada şu anda yayınlanmış bir bileşen yok.',
    ],

    'incidents' => [
        'heading' => 'Olaylar',
        'empty' => 'Bildirilen bir olay yok.',
        'started' => ':at itibarıyla başladı',

        // A date format rather than a sentence; see the English source. Turkish
        // puts the day before the month name and uses no comma.
        'day_format' => 'j F Y',
        'postmortem' => 'Olay sonrası inceleme',

        // The pair is inverted against the English: Turkish puts the verb after
        // the stamp, so the word moves to the `_after` half and the `_before`
        // half is empty.
        'postmortem_published_before' => '',
        'postmortem_published_after' => 'yayınlandı',

        'update_status' => [
            'detected' => 'Tespit edildi',
            'investigating' => 'İnceleniyor',
            'identified' => 'Belirlendi',
            'monitoring' => 'İzleniyor',
            'mitigated' => 'Etkisi azaltıldı',
            'resolved' => 'Çözüldü',
        ],

        'lifecycle_badge' => [
            'detected' => 'tespit edildi',
            'investigating' => 'inceleniyor',
            'identified' => 'belirlendi',
            'monitoring' => 'izleniyor',
            'mitigated' => 'etkisi azaltıldı',
            'resolved' => 'çözüldü',
        ],
    ],

    'maintenances' => [
        'heading' => 'Planlı bakım',
        'state' => [
            'in_progress' => 'sürüyor',
            'scheduled' => 'planlandı',
        ],
        'range_separator' => 'ile',
    ],

    'subscribe' => [
        'heading' => 'Güncellemelere abone ol',
        'body' => ':page yeni bir olay yayınladığında e-posta alın.',
        'action' => 'Abone ol',
    ],

    'confirmed' => [
        'title' => 'Abonelik onaylandı',
        'heading' => 'Aboneliğiniz aktif',
        'body' => ':page için olay güncellemelerini almaya başlayacaksınız. Her e-postada tek tıkla abonelikten çıkma bağlantısı bulunur.',
    ],

    'check_inbox' => [
        'title' => 'E-postanızı kontrol edin',
        'body' => 'Girdiğiniz adres :page sayfasına abone olabiliyorsa onay e-postası yolda. Aboneliği tamamlamak için içindeki bağlantıya tıklayın.',
    ],

    'unsubscribed' => [
        'title' => 'Abonelikten çıkıldı',
        'heading' => 'Aboneliğiniz sona erdi',
        'body' => 'Artık olay güncellemeleri almayacaksınız. Durum sayfasından istediğiniz zaman yeniden abone olabilirsiniz.',
    ],

    'emails' => [
        'confirm' => [
            'subject' => ':page aboneliğinizi onaylayın',
            'heading' => 'Aboneliğinizi onaylayın',

            // The page name follows this sentence in `<strong>`, so the Turkish
            // ends on a colon where the English ends on a preposition.
            'intro_before_page' => 'Şu durum sayfası için olay güncellemeleri almak istediniz:',

            'instruction' => 'Almaya başlamak için e-posta adresinizi onaylayın:',
            'action' => 'Aboneliği onayla',
            'ignore' => 'Bu isteği siz yapmadıysanız bu e-postayı yok sayabilirsiniz; hiçbir şey olmaz.',
        ],
        'maintenance' => [
            'subject' => ':page için planlı bakım',
            'heading' => 'Planlı bakım',

            // Follows the page name, which both languages put at the head of
            // this sentence.
            'intro_after_page' => 'sayfasında planlı bir bakım yaklaşıyor.',

            'components' => 'Etkilenen bileşenler:',
            'footer' => 'Bu e-postayı :page sayfasına aboneliğinizi onayladığınız için alıyorsunuz.',
            'unsubscribe' => 'Abonelikten çık',
        ],
    ],
];
