<?php

namespace App\Services\Monitoring;

use App\Enums\LocationBasis;

/**
 * Immutable outcome of {@see TargetLocation::resolve()}.
 *
 * `$country` and `$region` are populated ONLY when `$locationBasis` is
 * {@see LocationBasis::Geoip}: a CDN-fronted target reports its edge through
 * `$cdn` and leaves both null, on purpose, so a consumer cannot accidentally
 * read a stale or unrelated value out of the wrong pair.
 */
readonly class TargetLocationResult
{
    /**
     * @param  list<string>  $ips  The IPs the caller supplied for this target, or empty
     *                             when none could be resolved.
     * @param  string|null  $cdn  The detected CDN name (e.g. "Cloudflare"), or null when
     *                            no CDN header was present.
     */
    public function __construct(
        public array $ips,
        public ?string $cdn,
        public ?string $country,
        public ?string $region,
        public LocationBasis $locationBasis,
    ) {}
}
