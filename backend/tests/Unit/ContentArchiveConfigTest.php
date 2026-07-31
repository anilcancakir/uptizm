<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Locks the content-archive config and the pinned `content` disk.
 *
 * `allowed_content_types` is asserted with `assertSame` (order and shape, not
 * just membership) because Step 3 sends this exact list on the HMAC-signed
 * probe spec and a TypeScript Cloudflare Worker reimplements the same matcher
 * from a copy of this shape; a drift here breaks that reimplementation with
 * no test on the worker side catching it. `queue_backlog_limit` is asserted
 * as the literal integer 500 because Step 9's circuit breaker treats a null
 * as "no limit" rather than "not configured", so a null slipping in here
 * would silently disable the breaker.
 */
class ContentArchiveConfigTest extends TestCase
{
    /** The default max archived byte size, 1 MiB. */
    public function test_max_bytes_defaults_to_one_mebibyte(): void
    {
        $this->assertSame(1048576, config('content-archive.max_bytes'));
    }

    /** The pinned content disk uses the local driver. */
    public function test_content_disk_uses_the_local_driver(): void
    {
        $this->assertSame('local', config('filesystems.disks.content.driver'));
    }

    /** The content disk logs write failures rather than throwing or silently dropping them. */
    public function test_content_disk_logs_rather_than_throws_or_drops(): void
    {
        $this->assertFalse(config('filesystems.disks.content.throw'));
        $this->assertTrue(config('filesystems.disks.content.report'));
    }

    /**
     * The allowlist shape is pinned exactly: a flat list of lowercase strings
     * where a trailing `/` marks a prefix rule. Both sides (this config's
     * comment and the TypeScript worker) reimplement the matcher from this
     * literal value, so its order and shape must never drift.
     */
    public function test_allowed_content_types_matches_the_pinned_shape_exactly(): void
    {
        $this->assertSame(
            [
                'text/',
                'application/json',
                'application/xml',
            ],
            config('content-archive.allowed_content_types'),
        );
    }

    /** The sentinel guard defaults to null (disabled) so a dev checkout never aborts writes. */
    public function test_sentinel_defaults_to_null(): void
    {
        $this->assertNull(config('content-archive.sentinel'));
    }

    /**
     * The backlog limit is a real integer by default, never null, so Step 9's
     * circuit breaker cannot mistake "not configured" for "no limit".
     */
    public function test_queue_backlog_limit_defaults_to_the_integer_five_hundred(): void
    {
        $this->assertSame(500, config('content-archive.queue_backlog_limit'));
    }

    /** The normalizer version starts at 1. */
    public function test_normalizer_version_starts_at_one(): void
    {
        $this->assertSame(1, config('content-archive.normalizer_version'));
    }

    /** retention_days and queue carry their documented defaults. */
    public function test_retention_days_and_queue_carry_their_defaults(): void
    {
        $this->assertSame(30, config('content-archive.retention_days'));
        $this->assertSame('content', config('content-archive.queue'));
        $this->assertSame('content', config('content-archive.disk'));
    }

    /**
     * `TestCase::setUp()` fakes the content disk beside the existing preview
     * disk fake, so a test that writes to it leaves nothing on the real
     * filesystem under storage/app/private/monitor-content.
     */
    public function test_the_content_disk_is_faked_in_the_shared_test_case(): void
    {
        Storage::disk(config('content-archive.disk'))->put('probe.txt', 'content');

        $realRoot = storage_path('app/private/monitor-content');

        $this->assertTrue(
            ! is_dir($realRoot) || glob($realRoot.'/*') === [],
            'A write to the content disk reached the real filesystem, so TestCase::setUp() is not faking it.',
        );
    }
}
