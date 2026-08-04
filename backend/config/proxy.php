<?php

/*
|--------------------------------------------------------------------------
| Local Proxy Pool Configuration
|--------------------------------------------------------------------------
|
| The proxy sources that the local probe engine uses to egress through
| per-region exit pools. Each region may have AT MOST ONE source,
| configured as `['kind' => 'url'|'file', 'location' => env(...)]`.
|
| A region ABSENT from `sources` means the probe engine produces no
| reading for that region at all, rather than falling back to a default
| or the server's own network. This is load-bearing: a falling-back
| reading would fabricate an exit that is not part of the claimed
| infrastructure, and a public status page publishing it would mislead
| the operator. If you want to add a region to the catalog, source its
| list URL first, then add the region here.
|
| An empty source location (env key absent or set to '') leaves the region
| DECLARED BUT UNUSABLE: its pool never fills, `ProxyPool::hasRegion()`
| answers false, and the engine refuses that region rather than probing it.
| There is NO fallback to the Cloudflare worker for a catalog monitor and
| there must never be one: routing is decided on the owning team, so a
| catalog monitor only ever reaches this engine, and an engine that quietly
| egressed from this server instead would both fabricate the region and
| delete the network boundary the proxy exists to provide.
|
| The mathematical minimum for a resilient region set is two
| (MIN_AGREEING_REGIONS in the consensus check), but two regions leaves
| zero headroom: a single dark region reduces the reading count to one,
| and the Step 12 floor then marks every catalog page `STATUS_UNKNOWN`
| on the exact routine failure Step 11 exists to alarm about. Three is
| the smallest number that survives a one-region outage.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Proxy list sources, keyed by region
    |--------------------------------------------------------------------------
    |
    | Each region that has a configured source will have its pool refreshed
    | on the cadence below and used for local probes. A region absent here
    | means the probe engine never attempts to read it.
    |
    */

    'sources' => [
        'eu-west' => [
            'kind' => 'url',
            'location' => env('UPTIZM_PROXY_EU_WEST_SOURCE_LOCATION', ''),
        ],
        'us-east' => [
            'kind' => 'url',
            'location' => env('UPTIZM_PROXY_US_EAST_SOURCE_LOCATION', ''),
        ],
        'ap' => [
            'kind' => 'url',
            'location' => env('UPTIZM_PROXY_AP_SOURCE_LOCATION', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Refresh cadence
    |--------------------------------------------------------------------------
    |
    | How often, in minutes, each configured proxy source is fetched,
    | parsed and upserted into the pool.
    |
    */

    'refresh_minutes' => (int) env('UPTIZM_PROXY_REFRESH_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Pool health parameters
    |--------------------------------------------------------------------------
    |
    | base_backoff_seconds: the initial retry delay for a penalised exit
    |   when a probe transport-fails. Doubled with each successive failure.
    |
    | max_backoff_seconds: the ceiling for exponential backoff.
    |
    | failure_threshold: how many consecutive transport failures must occur
    |   on a region before the health watcher raises an operator alarm.
    |
    */

    'health' => [
        'base_backoff_seconds' => (int) env('UPTIZM_PROXY_BASE_BACKOFF_SECONDS', 300),
        'max_backoff_seconds' => (int) env('UPTIZM_PROXY_MAX_BACKOFF_SECONDS', 3600),
        'failure_threshold' => (int) env('UPTIZM_PROXY_FAILURE_THRESHOLD', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attempts per check
    |--------------------------------------------------------------------------
    |
    | How many exits in the same region the local probe engine may try
    | when the target is ambiguously unreachable (errno = 7, timeout).
    | A value of 2 means one initial attempt and one alternate exit.
    | Never an unbounded loop; exceeding this limit treats the target
    | as genuinely unreachable and records a down check, not a refusal.
    |
    */

    'attempts_per_check' => (int) env('UPTIZM_PROXY_ATTEMPTS_PER_CHECK', 2),

    /*
    |--------------------------------------------------------------------------
    | Minimum regions with a configured source
    |--------------------------------------------------------------------------
    |
    | The local probe engine requires at least this many regions with a
    | configured proxy source to operate safely. The mathematical floor
    | (MIN_AGREEING_REGIONS) is two, but that allows one dark region to
    | reduce the reading count to one, triggering the catalog-page
    | `STATUS_UNKNOWN` verdict on the exact class of failure the health
    | alarm exists to catch. Three survives one dead region.
    |
    */

    'minimum_regions' => (int) env('UPTIZM_PROXY_MINIMUM_REGIONS', 3),
];
