<?php

// Turkish translation of lang/en/notifications.php; keep every ":placeholder"
// token and key path identical to the English source.
return [
    // `:title`, çünkü olayların çoğu monitörün kesintiye girmesi değil: metrik
    // eşiği, AI anomalisi, süresi dolan sertifika ve elle açılan olay 200
    // dönen bir servis için "kesintide" diyordu. `:title` olayın kendi
    // başlığıdır ve gerçek bir kesintide yine ":monitor kesintide" olur.
    'incident_opened_subject' => '[Uptizm] :title',
    'incident_opened_greeting' => 'Olay açıldı',
    'incident_opened_state_line' => ':monitor şu anda ":lifecycle" durumunda.',
    'incident_opened_title' => ':title',
    'incident_opened_push_heading' => ':title',
    // The row heading on /settings/notifications, naming the event a person
    // is choosing channels for.
    'incident_opened_preference_label' => 'Olay açıldı',

    // Tırmanma kopyası "açıldı" demez: operatör bu olaya zaten bakıyor ve
    // yeniden açılış gibi okunan bir bildirim ayrı bir kesinti sanılır.
    'incident_escalated_subject' => '[Uptizm] :monitor kötüleşti',
    'incident_escalated_greeting' => 'Olay tırmandı',
    'incident_escalated_state_line' => ':monitor daha ağır bir seviyeye geçti ve ":lifecycle" durumunda.',
    'incident_escalated_title' => ':monitor kötüleşti',
    'incident_escalated_push_heading' => ':monitor kötüleşti',
    'incident_escalated_preference_label' => 'Olay kötüleşti',

    'incident_resolved_subject' => '[Uptizm] :monitor sorunu giderildi',
    'incident_resolved_greeting' => 'Olay çözüldü',
    'incident_resolved_line' => ':monitor üzerindeki olay çözüldü.',
    'incident_resolved_title' => ':monitor sorunu giderildi',
    'incident_resolved_push_heading' => ':monitor sorunu giderildi',
    'incident_resolved_preference_label' => 'Olay çözüldü',

    // The one-line body under an incident notification's title, composed by
    // `App\Support\Notifications\IncidentBody`. Each part is a fact that does not
    // go stale, because the in-app row is written once and read for as long as it
    // lives: "raised to critical" and "lasted 47 minutes" stay true, "started 3
    // minutes ago" would not.
    'body_raised_to' => ':severity seviyesine yükseldi',
    'body_lasted' => ':duration sürdü',

    // The severity tiers, named rather than printed as their stored token. The
    // column holds `critical`/`warn`/`info`, which is the vocabulary of the
    // database and not of the person being paged.
    'severity_critical' => 'Kritik',
    'severity_warn' => 'Uyarı',
    'severity_info' => 'Bilgi',

    'severity_line' => 'Önem derecesi: :severity.',
    'view_incident_action' => 'Olayı görüntüle',
    'unnamed_monitor' => 'Bir monitör',
];
