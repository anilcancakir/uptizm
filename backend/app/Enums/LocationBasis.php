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
 * The model-facing `region_basis` (`geoip`, `cdn_edge`, `content_language`,
 * `default`) is a different set answering a different question: not what a
 * lookup achieved, but why a region was chosen.
 *
 * There is NO mapping between the two, deliberately. A lookup outcome is not a
 * reason: only the model, which reads the location facts and can weigh them
 * against a page's language, answers anything other than `default`. The
 * deterministic path always answers `default`, because the region it suggests is
 * the one the request asked to probe from, and nothing this enum records took
 * part in choosing it. An earlier revision did map them, which produced a
 * suggestion justified by evidence that played no part in making it.
 */
enum LocationBasis: string
{
    case Geoip = 'geoip';
    case CdnEdge = 'cdn_edge';
    case Unresolved = 'unresolved';
}
