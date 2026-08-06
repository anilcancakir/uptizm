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

    /**
     * The normalizer version is a tripwire, not a fact about the past: it exists
     * so an archived hash can be recomputed rather than assumed comparable to a
     * new one. Changing how a body is normalized WITHOUT bumping it makes rows
     * written by the two algorithms silently incomparable, which is the failure
     * this assertion is here to force somebody to think about.
     *
     * 2 added the JSON rules: every numeric leaf and every ISO-8601 datetime is
     * erased before hashing, so a status document dedupes between real changes
     * instead of archiving a fresh blob on every check.
     */
    public function test_normalizer_version_is_bumped_with_the_algorithm(): void
    {
        $this->assertSame(2, config('content-archive.normalizer_version'));
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

        // Assert on THIS test's own write, not on the directory being globally
        // empty. The emptiness check read as stricter and was in fact broken: a
        // developer machine where the app has ever really run holds archived
        // bodies under this root (the ArchiveContent job writes them, which is
        // the product working), and the test then failed for a reason that has
        // nothing to do with whether the fake is installed. It also failed in
        // the worst polarity, green in CI and red locally.
        $realFile = storage_path('app/private/monitor-content/probe.txt');

        $this->assertFileDoesNotExist(
            $realFile,
            'A write to the content disk reached the real filesystem, so TestCase::setUp() is not faking it.',
        );

        $this->assertTrue(
            Storage::disk(config('content-archive.disk'))->exists('probe.txt'),
            'The write did not land on the fake either, so this test proved nothing.',
        );
    }
}
