<?php

namespace Tests\Unit\Support\Monitoring;

use App\Support\Monitoring\ContentTypeAllowList;
use Tests\TestCase;

/**
 * Exercises `ContentTypeAllowList::allows()` as a table against the default
 * `content-archive.allowed_content_types` rules.
 *
 * Step 9 calls this helper as defence in depth against an older worker that
 * might send an unfiltered content type, so the semantics live here as a
 * real shared unit rather than being reimplemented in each test that needs
 * them.
 */
class ContentTypeAllowListTest extends TestCase
{
    /** @return list<string> */
    private function defaultRules(): array
    {
        return config('content-archive.allowed_content_types');
    }

    /** A charset parameter is stripped before the prefix match. */
    public function test_accepts_a_prefix_match_with_a_charset_parameter(): void
    {
        $this->assertTrue(
            ContentTypeAllowList::allows('text/html; charset=utf-8', $this->defaultRules()),
        );
    }

    /** Matching is case-insensitive. */
    public function test_accepts_an_uppercase_header(): void
    {
        $this->assertTrue(ContentTypeAllowList::allows('TEXT/HTML', $this->defaultRules()));
    }

    /** An exact-match rule accepts its exact media type. */
    public function test_accepts_an_exact_match(): void
    {
        $this->assertTrue(ContentTypeAllowList::allows('application/json', $this->defaultRules()));
    }

    /** A media type outside every rule is rejected. */
    public function test_rejects_an_unlisted_exact_type(): void
    {
        $this->assertFalse(ContentTypeAllowList::allows('application/pdf', $this->defaultRules()));
    }

    /** A media type outside every prefix and exact rule is rejected. */
    public function test_rejects_an_unlisted_type_outside_every_prefix(): void
    {
        $this->assertFalse(ContentTypeAllowList::allows('image/png', $this->defaultRules()));
    }

    /** An empty header is rejected. */
    public function test_rejects_an_empty_header(): void
    {
        $this->assertFalse(ContentTypeAllowList::allows('', $this->defaultRules()));
    }

    /** A null header is rejected. */
    public function test_rejects_a_null_header(): void
    {
        $this->assertFalse(ContentTypeAllowList::allows(null, $this->defaultRules()));
    }
}
