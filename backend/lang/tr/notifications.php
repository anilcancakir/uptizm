<?php

// Turkish translation of lang/en/notifications.php; keep every ":placeholder"
// token and key path identical to the English source.
return [
    'incident_opened_subject' => '[Uptizm] :monitor kesintide',
    'incident_opened_greeting' => 'Olay açıldı',
    'incident_opened_state_line' => ':monitor şu anda ":lifecycle" durumunda.',
    'incident_opened_title' => ':monitor kesintide',
    'incident_opened_push_heading' => ':monitor kesintide',

    // Tırmanma kopyası "açıldı" demez: operatör bu olaya zaten bakıyor ve
    // yeniden açılış gibi okunan bir bildirim ayrı bir kesinti sanılır.
    'incident_escalated_subject' => '[Uptizm] :monitor kötüleşti',
    'incident_escalated_greeting' => 'Olay tırmandı',
    'incident_escalated_state_line' => ':monitor daha ağır bir seviyeye geçti ve ":lifecycle" durumunda.',
    'incident_escalated_title' => ':monitor kötüleşti',
    'incident_escalated_push_heading' => ':monitor kötüleşti',

    'incident_resolved_subject' => '[Uptizm] :monitor sorunu giderildi',
    'incident_resolved_greeting' => 'Olay çözüldü',
    'incident_resolved_line' => ':monitor üzerindeki olay çözüldü.',
    'incident_resolved_title' => ':monitor sorunu giderildi',
    'incident_resolved_push_heading' => ':monitor sorunu giderildi',

    'severity_line' => 'Önem derecesi: :severity.',
    'view_incident_action' => 'Olayı görüntüle',
    'unnamed_monitor' => 'Bir monitör',
];
