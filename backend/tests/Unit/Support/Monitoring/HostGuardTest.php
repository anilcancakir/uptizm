<?php

namespace Tests\Unit\Support\Monitoring;

use App\Support\Monitoring\HostGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Locks the SSRF denylist shared by the Store and Update monitor requests:
 * loopback, RFC1918, link-local (cloud metadata), integer-encoded IPv4
 * literals, and reserved names are blocked, while a public host passes.
 *
 * These assertions mirror the guarantees the inline request logic held
 * before extraction, so a regression here surfaces as a failed unit test
 * rather than a silently reopened SSRF hole.
 */
class HostGuardTest extends TestCase
{
    private HostGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new HostGuard;
    }

    /** Loopback resolves into 127.0.0.0/8 and is blocked. */
    public function test_blocks_loopback_address(): void
    {
        $this->assertTrue($this->guard->isBlockedHost('127.0.0.1'));
    }

    /** A literal public address resolves to itself, so a caller gets an answer without DNS. */
    public function test_resolves_a_public_literal_to_itself(): void
    {
        $this->assertSame(['203.0.113.5'], $this->guard->resolvePublicHostIps('203.0.113.5'));
    }

    /**
     * Every denied shape yields an empty list rather than a partial answer,
     * including the integer-encoded loopback that reads as a hostname.
     */
    public function test_resolving_a_denied_host_yields_no_addresses(): void
    {
        $this->assertSame([], $this->guard->resolvePublicHostIps('127.0.0.1'));
        $this->assertSame([], $this->guard->resolvePublicHostIps('10.0.0.5'));
        $this->assertSame([], $this->guard->resolvePublicHostIps('169.254.169.254'));
        $this->assertSame([], $this->guard->resolvePublicHostIps('2130706433'));
        $this->assertSame([], $this->guard->resolvePublicHostIps('localhost'));
        $this->assertSame([], $this->guard->resolvePublicHostIps('db.internal'));
        $this->assertSame([], $this->guard->resolvePublicHostIps(''));
    }

    /** An RFC1918 private address is blocked. */
    public function test_blocks_rfc1918_private_address(): void
    {
        $this->assertTrue($this->guard->isBlockedHost('10.0.0.5'));
    }

    /** The cloud-metadata link-local address is blocked. */
    public function test_blocks_link_local_metadata_address(): void
    {
        $this->assertTrue($this->guard->isBlockedHost('169.254.169.254'));
    }

    /** An integer-encoded IPv4 literal (2130706433 == 127.0.0.1) is blocked. */
    public function test_blocks_integer_encoded_ipv4_literal(): void
    {
        $this->assertTrue($this->guard->isBlockedHost('2130706433'));
    }

    /** Reserved names never resolve to a public host and are blocked. */
    public function test_blocks_reserved_names(): void
    {
        $this->assertTrue($this->guard->isBlockedHost('localhost'));
        $this->assertTrue($this->guard->isBlockedHost('api.internal'));
    }

    /** An IPv4-mapped IPv6 literal cannot smuggle an internal target past the guard. */
    public function test_blocks_ipv4_mapped_ipv6_internal_address(): void
    {
        $this->assertTrue($this->guard->isBlockedHost('::ffff:169.254.169.254'));
        $this->assertTrue($this->guard->isBlockedHost('::ffff:10.0.0.5'));
        $this->assertTrue($this->guard->isBlockedHost('[::ffff:127.0.0.1]'));

        // A mapped PUBLIC address must still pass: the unwrap must not over-block.
        $this->assertFalse($this->guard->isBlockedHost('::ffff:8.8.8.8'));
    }

    /** 0.0.0.0 reaches localhost on Linux and is blocked (0.0.0.0/8). */
    public function test_blocks_this_host_address(): void
    {
        $this->assertTrue($this->guard->isBlockedHost('0.0.0.0'));
    }

    /** Native IPv6 loopback, unspecified, ULA, and link-local are blocked. */
    public function test_blocks_internal_ipv6_addresses(): void
    {
        $this->assertTrue($this->guard->isBlockedHost('::1'));
        $this->assertTrue($this->guard->isBlockedHost('::'));
        $this->assertTrue($this->guard->isBlockedHost('fd00::1'));
        $this->assertTrue($this->guard->isBlockedHost('fe80::1'));
    }

    /** A public host resolves outside every blocked range and passes. */
    public function test_allows_public_host(): void
    {
        $this->assertFalse($this->guard->isBlockedHost('example.com'));
    }

    /** assertUrlAllowed throws for a URL whose host targets an internal address. */
    public function test_assert_url_allowed_throws_for_internal_host(): void
    {
        $this->expectException(ValidationException::class);

        $this->guard->assertUrlAllowed('http://10.0.0.5/health');
    }

    /** assertUrlAllowed passes silently for a public URL. */
    public function test_assert_url_allowed_passes_for_public_host(): void
    {
        $this->guard->assertUrlAllowed('https://example.com/health');

        $this->expectNotToPerformAssertions();
    }
}
