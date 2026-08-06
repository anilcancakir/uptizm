<?php

namespace Tests\Unit\Support\Monitoring;

use App\Support\Monitoring\ProbeHeaderAllowList;
use Tests\TestCase;

/**
 * Exercises `ProbeHeaderAllowList::filter()`, the fail-closed boundary
 * between a probe's raw response headers and the monitor-setup prompt.
 *
 * Every case here is a NAME decision: nothing filters by value, because a
 * target chooses its own header values and a value-based rule would be
 * something a hostile origin could learn to defeat.
 */
class ProbeHeaderAllowListTest extends TestCase
{
    /** A diagnostic header on the closed list survives untouched. */
    public function test_keeps_an_allowlisted_header(): void
    {
        $filtered = ProbeHeaderAllowList::filter([
            'server' => 'nginx',
            'cf-ray' => '8a1b2c3d4e5f6g7h-FRA',
        ]);

        $this->assertSame('nginx', $filtered['server']);
        $this->assertSame('8a1b2c3d4e5f6g7h-FRA', $filtered['cf-ray']);
    }

    /** `set-cookie` and an invented name are dropped, everything else on the list survives. */
    public function test_drops_set_cookie_and_an_unlisted_header(): void
    {
        $filtered = ProbeHeaderAllowList::filter([
            'set-cookie' => 'session=abc123; HttpOnly',
            'x-secret-token' => 'super-secret',
            'server' => 'nginx',
            'cf-ray' => '8a1b2c3d4e5f6g7h-FRA',
        ]);

        $this->assertArrayNotHasKey('set-cookie', $filtered);
        $this->assertArrayNotHasKey('x-secret-token', $filtered);
        $this->assertArrayHasKey('server', $filtered);
        $this->assertArrayHasKey('cf-ray', $filtered);
    }

    /** Name matching is case-insensitive: mixed case and all caps both drop. */
    public function test_name_matching_is_case_insensitive(): void
    {
        $this->assertSame([], ProbeHeaderAllowList::filter(['Set-Cookie' => 'session=abc123']));
        $this->assertSame([], ProbeHeaderAllowList::filter(['SET-COOKIE' => 'session=abc123']));
    }

    /** An allowlisted name in a non-lowercase form still survives, lowercased. */
    public function test_an_allowlisted_name_survives_regardless_of_case(): void
    {
        $this->assertSame(['server' => 'nginx'], ProbeHeaderAllowList::filter(['Server' => 'nginx']));
    }

    /**
     * A value that is not a string is handled rather than cast.
     *
     * `CheckResult` builds `response_headers` with a bare array cast and never
     * checks the leaf type, so a `(string)` cast here on a list would raise a
     * warning Laravel rethrows as an `ErrorException`, inside a request whose
     * whole contract is that it degrades rather than throws. A list is JOINED
     * rather than dropped, because `link` legitimately carries several `rel=`
     * entries and that fingerprint is why the name is on the list at all.
     */
    public function test_a_non_string_header_value_is_joined_or_dropped_never_cast(): void
    {
        $kept = ProbeHeaderAllowList::filter([
            'Link' => ['<https://example.com/wp-json/>; rel="https://api.w.org/"', '<https://example.com/>; rel=shortlink'],
            'Age' => 42,
            'Server' => ['nested' => ['deep']],
        ]);

        $this->assertSame(
            '<https://example.com/wp-json/>; rel="https://api.w.org/", <https://example.com/>; rel=shortlink',
            $kept['link'],
        );
        $this->assertSame('42', $kept['age']);
        $this->assertArrayNotHasKey('server', $kept, 'a nested array is not a header value');
    }

    /** A kept value over the cap is truncated to it, not dropped. */
    public function test_truncates_an_oversized_value_to_the_cap(): void
    {
        $filtered = ProbeHeaderAllowList::filter([
            'server' => str_repeat('a', 5000),
        ]);

        $this->assertArrayHasKey('server', $filtered);
        $this->assertSame(ProbeHeaderAllowList::VALUE_MAX_LENGTH, mb_strlen($filtered['server']));
    }

    /** The result is ordered by the allowlist's own order, not the target's. */
    public function test_preserves_allowlist_order_regardless_of_input_order(): void
    {
        $filtered = ProbeHeaderAllowList::filter([
            'link' => '<https://example.com/wp-json/>; rel="https://api.w.org/"',
            'cf-ray' => '8a1b2c3d4e5f6g7h-FRA',
            'server' => 'nginx',
            'content-type' => 'application/json',
        ]);

        $this->assertSame(
            ['content-type', 'server', 'cf-ray', 'link'],
            array_keys($filtered),
        );
    }

    /** Security-posture headers are deliberately absent, even when present on the target. */
    public function test_drops_security_posture_headers_the_prompt_never_reads(): void
    {
        $filtered = ProbeHeaderAllowList::filter([
            'strict-transport-security' => 'max-age=63072000',
            'x-frame-options' => 'SAMEORIGIN',
            'server' => 'nginx',
        ]);

        $this->assertArrayNotHasKey('strict-transport-security', $filtered);
        $this->assertArrayNotHasKey('x-frame-options', $filtered);
        $this->assertArrayHasKey('server', $filtered);
    }

    /** A realistic Cloudflare-fronted WordPress header set keeps only the diagnostics. */
    public function test_a_realistic_cloudflare_wordpress_header_set_drops_every_credential(): void
    {
        $filtered = ProbeHeaderAllowList::filter([
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'max-age=600, public',
            'X-Powered-By' => 'PHP/8.3',
            'Link' => '<https://example.com/wp-json/>; rel="https://api.w.org/"',
            'CF-Cache-Status' => 'HIT',
            'CF-RAY' => '8a1b2c3d4e5f6g7h-FRA',
            'Set-Cookie' => 'wordpress_logged_in=abc123; path=/',
            'Strict-Transport-Security' => 'max-age=63072000',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);

        $this->assertArrayNotHasKey('set-cookie', $filtered);
        $this->assertArrayNotHasKey('strict-transport-security', $filtered);
        $this->assertArrayNotHasKey('x-frame-options', $filtered);
        $this->assertSame(
            ['content-type', 'x-powered-by', 'cache-control', 'cf-cache-status', 'cf-ray', 'link'],
            array_keys($filtered),
        );
    }
}
