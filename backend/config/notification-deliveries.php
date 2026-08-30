<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Notification Deliveries
    |--------------------------------------------------------------------------
    |
    | Configuration for the notification_deliveries retention sweep: the
    | attempted-delivery audit trail this table carries is kept for a bounded
    | window, then pruned by App\Jobs\PruneNotificationDeliveries.
    |
    */

    // How long an attempted delivery row is kept before pruning. 90 days:
    // comfortably enough for the future SLA read this table is built for,
    // without holding an audit trail indefinitely on a table whose volume
    // already grows with every incident lifecycle event.
    'retention_days' => (int) env('NOTIFICATION_DELIVERIES_RETENTION_DAYS', 90),
];
