<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TimescaleDB Retention Windows
    |--------------------------------------------------------------------------
    |
    | Retention policies applied to the monitor_checks / monitor_metric_values
    | hypertables and their continuous aggregates. Values are in days.
    |
    */

    'retention' => [
        'raw_days' => (int) env('TIMESCALE_RAW_RETENTION_DAYS', 90),
        'hourly_days' => (int) env('TIMESCALE_HOURLY_RETENTION_DAYS', 365),
        'daily_days' => (int) env('TIMESCALE_DAILY_RETENTION_DAYS', 730),
    ],

    /*
    | Continuous aggregate refresh schedules. Timescale will rebuild the
    | rollups within these lag/window parameters.
    */
    'aggregates' => [
        'hourly' => [
            'start_offset' => '7 days',
            'end_offset' => '1 hour',
            'schedule_interval' => '1 hour',
        ],
        'daily' => [
            'start_offset' => '30 days',
            'end_offset' => '1 day',
            'schedule_interval' => '6 hours',
        ],
    ],
];
