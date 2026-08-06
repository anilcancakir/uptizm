<?php

namespace App\Services\Monitoring;

use App\Enums\LocationBasis;
use App\Rules\TurnstileRule;
use App\Support\Monitoring\HostGuard;
use App\Support\Monitoring\ProbeHeaderAllowList;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves what is actually known about a monitor target's location,
 * refusing to fabricate one when a CDN is standing in front of it.
 *
 * `colo` (our own Cloudflare POP) and `exit_via` (a proxy exit) describe
 * where the PROBE ran from, and nothing in this codebase otherwise knows
 * where the TARGET is. This service closes that gap honestly: it detects a
 * CDN from the allowlisted response headers, and only when none is present
 * does it ask an optional geo provider. Once a CDN is detected, the geo
 * provider is never even called, because an anycast address locates an edge,
 * not an origin, and this product has already shipped and removed a
 * fabricated measurement once.
 *
 * DNS RESOLUTION IS DELIBERATELY NOT DONE HERE
 *
 * {@see HostGuard} is the only DNS code in this
 * backend and this class does not reimplement it. Its public surface does
 * not fit this call site though: `resolveAndAssertAllowed()` is https-only
 * and throws on a port or embedded credentials, which an arbitrary monitor
 * target may well carry, and `isBlockedHost()` resolves internally but
 * returns only a boolean. So the caller supplies `$ips` (from wherever it
 * already has them); an empty list is treated the same as an unresolvable
 * host, which is what {@see resolve()}'s `unresolved` case means for both.
 *
 * The geo lookup follows {@see TurnstileRule}'s optional-integration
 * shape: dormant and silent while `services.ipinfo.token` is unset, and a
 * `ConnectionException` degrades to `unresolved` rather than failing the
 * request it is called from.
 */
class TargetLocation
{
    /**
     * How long to wait on the geo provider before giving up.
     */
    private const int TIMEOUT_SECONDS = 5;

    /**
     * Header names that name a CDN POP, and the CDN each one proves.
     *
     * Presence alone proves the CDN: `cf-ray` and `cf-cache-status` are
     * minted only by Cloudflare's edge, `x-amz-cf-pop` only by CloudFront,
     * `x-served-by` only by Fastly. All three are drawn from the same
     * allowlist {@see ProbeHeaderAllowList} already
     * filtered through, so this never reads a header the target could not
     * have sent unfiltered.
     *
     * @var array<string, string>
     */
    private const array CDN_HEADER_NAMES = [
        'cf-ray' => 'Cloudflare',
        'cf-cache-status' => 'Cloudflare',
        'x-amz-cf-pop' => 'Amazon CloudFront',
        'x-served-by' => 'Fastly',
    ];

    /**
     * `server` substrings that name a CDN. `server: nginx` proves nothing
     * either way (plenty of origins run nginx directly), so it is
     * deliberately absent; only a value a CDN itself would send is listed.
     *
     * @var array<string, string>
     */
    private const array CDN_SERVER_MARKERS = [
        'cloudflare' => 'Cloudflare',
        'cloudfront' => 'Amazon CloudFront',
        'fastly' => 'Fastly',
        'akamaighost' => 'Akamai',
    ];

    /**
     * Resolve the target's posture from the given URL, allowlisted response
     * headers, and any already-resolved IPs.
     *
     * @param  string  $url  The monitor target URL; carried for the geo provider's
     *                       own diagnostics and future use, not re-resolved here.
     * @param  array<string, string>  $headers  Response headers already filtered through
     *                                          `ProbeHeaderAllowList`, never the raw set.
     * @param  list<string>  $ips  IPs the caller already resolved for this target, or
     *                             empty when none could be.
     */
    public function resolve(string $url, array $headers, array $ips = []): TargetLocationResult
    {
        $cdn = $this->detectCdn($headers);

        // 1. A detected CDN is the honesty rule itself: report the edge, never
        //    an origin, and skip the geo lookup entirely rather than let it
        //    answer a question that has already been settled.
        if ($cdn !== null) {
            return new TargetLocationResult(
                ips: $ips,
                cdn: $cdn,
                country: null,
                region: null,
                locationBasis: LocationBasis::CdnEdge,
            );
        }

        // 2. No CDN detected: ask the optional geo provider about the first
        //    resolved IP, degrading to `unresolved` on every failure mode.
        $geo = $this->lookupGeo($url, $ips);

        if ($geo === null) {
            return new TargetLocationResult(
                ips: $ips,
                cdn: null,
                country: null,
                region: null,
                locationBasis: LocationBasis::Unresolved,
            );
        }

        return new TargetLocationResult(
            ips: $ips,
            cdn: null,
            country: $geo['country'],
            region: $geo['region'],
            locationBasis: LocationBasis::Geoip,
        );
    }

    /**
     * Detect a CDN from the allowlisted headers, or null when none is proven.
     *
     * The absence of every marker here does NOT prove the target is its own
     * origin; it only means this service found no evidence of a CDN, which is
     * exactly why an unproven target still has to go through the geo lookup
     * rather than being assumed to be an origin.
     *
     * @param  array<string, string>  $headers
     */
    private function detectCdn(array $headers): ?string
    {
        foreach (self::CDN_HEADER_NAMES as $name => $cdn) {
            if (array_key_exists($name, $headers)) {
                return $cdn;
            }
        }

        $server = strtolower((string) ($headers['server'] ?? ''));

        foreach (self::CDN_SERVER_MARKERS as $marker => $cdn) {
            if ($server !== '' && str_contains($server, $marker)) {
                return $cdn;
            }
        }

        return null;
    }

    /**
     * Look up the country/region for the first of the given IPs via ipinfo,
     * or null on every degrade path: no token configured, no IP to look up,
     * a transport failure, or a response the provider itself refused.
     *
     * @param  list<string>  $ips
     * @return array{country: string, region: ?string}|null
     */
    private function lookupGeo(string $url, array $ips): ?array
    {
        $token = config('services.ipinfo.token');

        if (blank($token) || $ips === []) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->get("https://ipinfo.io/{$ips[0]}/json", ['token' => $token]);
        } catch (ConnectionException) {
            // The HOST, never the URL. A monitor target is frequently
            // `…/health?token=…`, and a log line is one of the three places the
            // credential invariant names. The host is the whole diagnostic value
            // here anyway: this line records that a geo provider could not be
            // reached about an address, not what path the target serves.
            Log::warning('ipinfo lookup was unreachable while resolving a monitor target.', [
                'host' => parse_url($url, PHP_URL_HOST) ?: 'n/a',
            ]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $country = $response->json('country');

        if (! is_string($country) || $country === '') {
            return null;
        }

        $region = $response->json('region');

        return [
            'country' => $country,
            'region' => is_string($region) && $region !== '' ? $region : null,
        ];
    }
}
