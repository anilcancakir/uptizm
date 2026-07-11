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

        $this->guard = new HostGuard();
    }

    /** Loopback resolves into 127.0.0.0/8 and is blocked. */
    public function test_blocks_loopback_address(): void
    {
        $this->assertTrue($this->guard->isBlockedHost('127.0.0.1'));
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
