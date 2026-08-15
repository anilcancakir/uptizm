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
    | There is deliberately no region list here. Which regions exist is the
    | `MonitorRegion` enum's answer and nothing else's: it is what
    | `RelayClient::dispatch()` validates against, what the monitor write path
    | validates against, and what the landing page counts. A `regions` key used
    | to sit here, was read by nothing, and listed two of the five the enum
    | carries, so anyone who trusted it would have concluded that the region
    | production probes every minute was unsupported.
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
    | HMAC header names and replay window.
    */
    'signature_header' => 'X-Relay-Signature',
    'timestamp_header' => 'X-Relay-Timestamp',
    'signature_ttl_seconds' => (int) env('RELAY_SIGNATURE_TTL', 300),
];
