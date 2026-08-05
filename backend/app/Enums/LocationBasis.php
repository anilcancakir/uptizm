<?php

namespace App\Enums;

use App\Services\Monitoring\TargetLocation;

/**
 * What {@see TargetLocation} actually managed to
 * resolve for a monitor target, as opposed to why a MODEL chose a region.
 *
 * This is a lookup OUTCOME, not a reasoning trail: `geoip` means the geo
 * provider answered, `cdn_edge` means an anycast address was detected and an
 * origin location was deliberately withheld, `unresolved` covers every other
 * case (no token configured, no IPs to look up, or the provider failed).
 *
 * Step 8's model-facing `region_basis` is a DIFFERENT enum with an
 * overlapping but distinct case set (`geoip`, `cdn_edge`, `content_language`,
 * `default`): it describes why the model picked a region, and `unresolved`
 * has no place in it because the deterministic fallback path never reads a
 * page's language. The mapping between the two lives at Step 9's call site,
 * not here.
 */
enum LocationBasis: string
{
    case Geoip = 'geoip';
    case CdnEdge = 'cdn_edge';
    case Unresolved = 'unresolved';
}
