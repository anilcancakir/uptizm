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

    // The lane the nightly sweep runs on. Named rather than inherited: a job
    // that does not call onQueue() lands on `default`, which works here only
    // because `default` happens to sit in supervisor-1's queue list in
    // config/horizon.php. Drop it from that list and the sweep stops running
    // with no error anywhere, which is the silent failure this repo's
    // per-chain queue-config tests exist to prevent. Keep this value inside
    // supervisor-1's list.
    'queue' => env('NOTIFICATION_DELIVERIES_QUEUE', 'default'),
];
