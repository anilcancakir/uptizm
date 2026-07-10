<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Relay (Cloudflare Worker) Configuration
    |--------------------------------------------------------------------------
    |
    | A single Cloudflare Worker fronts region-pinned Durable Objects.
    | The API signs every request with HMAC-SHA256 using `secret`;
    | the worker verifies, then dispatches into the DO that matches
    | the payload's `region` field.
    |
    */

    'secret' => env('RELAY_SECRET'),

    'timeout_seconds' => (int) env('RELAY_TIMEOUT_SECONDS', 45),

    /*
    | Base URL of the regional-checker worker (e.g.
    | https://uptizm-regional-checker.<subdomain>.workers.dev).
    */
    'url' => env('RELAY_URL', 'http://localhost:8787'),

    /*
    | Canonical list of regions (demo: 2-region set).
    | Full constellation (5 regions): us-east, us-west, eu-west, eu-central, ap
    */
    'regions' => [
        'us-east',
        'eu-west',
    ],

    /*
    | HMAC header names and replay window.
    */
    'signature_header' => 'X-Relay-Signature',
    'timestamp_header' => 'X-Relay-Timestamp',
    'signature_ttl_seconds' => (int) env('RELAY_SIGNATURE_TTL', 300),
];
