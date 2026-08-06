<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\LocationBasis;
use App\Services\Monitoring\TargetLocation;
use App\Support\Monitoring\HostGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises `TargetLocation::resolve()`, the honesty boundary between what a
 * probe actually proves (a Cloudflare/CDN edge) and what a geo lookup would
 * otherwise be willing to claim (an origin location).
 *
 * `TargetLocation` never resolves DNS itself: {@see HostGuard}
 * has no public entry point that returns the IPs for an arbitrary monitor
 * URL (its https-only `resolveAndAssertAllowed()` rejects a port or plain
 * http), so every case here supplies IPs the way a real caller would: as an
 * argument, not re-derived.
 */
class TargetLocationTest extends TestCase
{
    /** A detected CDN header wins even when a geo lookup would have answered; no lookup is attempted. */
    public function test_a_cdn_header_reports_cdn_edge_and_withholds_the_country(): void
    {
        config(['services.ipinfo.token' => 'test-token']);
        Http::fake([
            'ipinfo.io/*' => Http::response(['country' => 'DE', 'region' => 'Hesse']),
        ]);

        $result = (new TargetLocation)->resolve(
            url: 'https://example.com/health',
            headers: ['cf-ray' => '8a1b2c3d4e5f6g7h-FRA'],
            ips: ['203.0.113.5'],
        );

        $this->assertSame(LocationBasis::CdnEdge, $result->locationBasis);
        $this->assertNull($result->country);
        $this->assertSame('Cloudflare', $result->cdn);
        Http::assertNothingSent();
    }

    /** No CDN header and a configured token: the geo lookup answers and the basis is `geoip`. */
    public function test_a_configured_token_resolves_geoip_when_no_cdn_is_detected(): void
    {
        config(['services.ipinfo.token' => 'test-token']);
        Http::fake([
            'ipinfo.io/*' => Http::response(['country' => 'US', 'region' => 'California']),
        ]);

        $result = (new TargetLocation)->resolve(
            url: 'https://example.com/health',
            headers: ['server' => 'nginx'],
            ips: ['198.51.100.7'],
        );

        $this->assertSame(LocationBasis::Geoip, $result->locationBasis);
        $this->assertSame('US', $result->country);
        $this->assertSame('California', $result->region);
        $this->assertNull($result->cdn);
    }

    /** No token configured: the lookup stays dormant and silent, never sending a request. */
    public function test_no_configured_token_yields_unresolved_without_a_request(): void
    {
        config(['services.ipinfo.token' => null]);
        Http::fake();

        $result = (new TargetLocation)->resolve(
            url: 'https://example.com/health',
            headers: [],
            ips: ['198.51.100.7'],
        );

        $this->assertSame(LocationBasis::Unresolved, $result->locationBasis);
        Http::assertNothingSent();
    }

    /** A transport failure from the geo provider degrades to `unresolved`, never an exception. */
    public function test_a_connection_exception_from_the_geo_provider_degrades_to_unresolved(): void
    {
        config(['services.ipinfo.token' => 'test-token']);
        Http::fake(function () {
            throw new ConnectionException('ipinfo unreachable');
        });

        $result = (new TargetLocation)->resolve(
            url: 'https://example.com/health',
            headers: [],
            ips: ['198.51.100.7'],
        );

        $this->assertSame(LocationBasis::Unresolved, $result->locationBasis);
        $this->assertNull($result->country);
    }

    /** An unresolvable host (no IPs to hand it) yields `unresolved` with an empty IP list. */
    public function test_an_unresolvable_host_yields_unresolved_with_an_empty_ip_list(): void
    {
        config(['services.ipinfo.token' => 'test-token']);
        Http::fake();

        $result = (new TargetLocation)->resolve(
            url: 'https://does-not-resolve.example.invalid/',
            headers: [],
            ips: [],
        );

        $this->assertSame(LocationBasis::Unresolved, $result->locationBasis);
        $this->assertSame([], $result->ips);
        Http::assertNothingSent();
    }
}
