<?php

namespace Tests\Unit\Marketing;

use App\Support\Marketing\LegalDocument;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks the Markdown pipeline every legal/support page (Privacy, Terms, Contact, FAQ) renders
 * through: heading anchors that a TOC can actually reach, locale fallback, and the
 * cache-then-substitute ordering that lets an operator-identity config change reach the page
 * without waiting on the Markdown file's mtime.
 *
 * Fixtures are written under resources/legal/ because LegalDocument::render() resolves that
 * path directly (resource_path()), not injectably; every fixture name is unique per test and
 * removed in tearDown so nothing here lingers in the real content directory.
 */
class LegalDocumentTest extends TestCase
{
    /** Every fixture path created by a test, deleted in tearDown regardless of outcome. */
    private array $fixturePaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The array cache store persists across test methods within the same process
        // (phpunit.xml sets CACHE_STORE=array), so a stale entry from one test could leak
        // into the next and hide a cache-key bug. Start every test from an empty cache.
        Cache::flush();

        // resources/legal/ is where every legal/support page's Markdown source lives (never
        // storage/app/, whose .gitignore is "*"); it does not exist until the first real page
        // is authored, but this test's fixtures need somewhere to write.
        if (! is_dir(resource_path('legal'))) {
            mkdir(resource_path('legal'), recursive: true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->fixturePaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->fixturePaths = [];

        parent::tearDown();
    }

    /** Write a fixture Markdown file under resources/legal/ and track it for cleanup. */
    private function writeFixture(string $page, string $locale, string $markdown): string
    {
        $path = resource_path("legal/{$page}.{$locale}.md");
        file_put_contents($path, $markdown);
        $this->fixturePaths[] = $path;

        return $path;
    }

    /**
     * Rendering a fixture with three heading levels returns an id per heading reachable by
     * "#slug", and a TOC array whose slugs equal those ids exactly, prefix included.
     *
     * The expected slugs here ("a", "b", "c") were confirmed against this repo's installed
     * league/commonmark by running the extension's default config in tinker first: the
     * default puts a "content-" prefixed id on an inner permalink <a>, not the heading
     * element, so an assertion on "<h2 id=" against the DEFAULT config cannot pass. This test
     * pins the id_prefix='' / apply_id_to_heading=true config instead, which writes a bare id
     * directly on the heading.
     */
    public function test_render_returns_headings_with_ids_matching_toc_slugs(): void
    {
        $page = 'toc-fixture-'.uniqid();
        $this->writeFixture($page, 'en', "# A\n\n## B\n\n### C\n");

        $document = (new LegalDocument)->render($page, 'en');

        $this->assertSame(
            [
                ['level' => 1, 'text' => 'A', 'slug' => 'a'],
                ['level' => 2, 'text' => 'B', 'slug' => 'b'],
                ['level' => 3, 'text' => 'C', 'slug' => 'c'],
            ],
            $document['toc'],
        );

        foreach ($document['toc'] as $heading) {
            $this->assertStringContainsString(
                sprintf('id="%s"', $heading['slug']),
                $document['html'],
            );
        }
    }

    /** A missing locale file falls back to the default locale's file. */
    public function test_missing_translation_falls_back_to_default_locale(): void
    {
        $page = 'fallback-fixture-'.uniqid();
        $this->writeFixture($page, 'en', "# English only\n");

        $document = (new LegalDocument)->render($page, 'tr');

        $this->assertStringContainsString('English only', $document['html']);
    }

    /** A page missing for BOTH the requested and the default locale is a hard failure. */
    public function test_missing_both_locales_throws(): void
    {
        $this->expectException(RuntimeException::class);

        (new LegalDocument)->render('nonexistent-page-'.uniqid(), 'tr');
    }

    /** Touching the file (a content edit, which changes mtime) changes the cache key. */
    public function test_touching_the_file_changes_the_cached_output(): void
    {
        $page = 'mtime-fixture-'.uniqid();
        $path = $this->writeFixture($page, 'en', "# First version\n");

        $first = (new LegalDocument)->render($page, 'en');
        $this->assertStringContainsString('First version', $first['html']);

        // Force a distinct mtime: some filesystems have 1-second mtime resolution, and a
        // same-second rewrite could otherwise produce an identical cache key.
        file_put_contents($path, "# Second version\n");
        touch($path, time() + 5);

        $second = (new LegalDocument)->render($page, 'en');

        $this->assertStringContainsString('Second version', $second['html']);
        $this->assertStringNotContainsString('First version', $second['html']);
    }

    /**
     * render() applies $replacements to the CACHED html, proved by rendering once, changing a
     * replacement value, then rendering again with the file untouched and seeing the new value.
     *
     * This is the test the plan names specifically: the cache key is built from page, locale
     * and mtime, so a naive implementation that bakes $replacements into the cached payload
     * (or into the key) would still pass a superficial test under CACHE_STORE=array while
     * production served stale text forever, because a config change never touches the
     * Markdown file's mtime.
     */
    public function test_replacements_apply_to_the_cached_html_and_see_a_config_change(): void
    {
        $page = 'replacement-fixture-'.uniqid();
        $this->writeFixture($page, 'en', "# Title\n\nContact us at [[legal.contact_email]].\n");

        $first = (new LegalDocument)->render($page, 'en', [
            '[[legal.contact_email]]' => 'first@example.com',
        ]);
        $this->assertStringContainsString('first@example.com', $first['html']);

        $second = (new LegalDocument)->render($page, 'en', [
            '[[legal.contact_email]]' => 'second@example.com',
        ]);

        $this->assertStringContainsString('second@example.com', $second['html']);
        $this->assertStringNotContainsString('first@example.com', $second['html']);
    }

    /** An unreplaced [[key]] placeholder survives into the output rather than vanishing. */
    public function test_unreplaced_placeholder_survives_into_the_output(): void
    {
        $page = 'placeholder-fixture-'.uniqid();
        $this->writeFixture($page, 'en', "# Title\n\nReach us at [[legal.contact_email]].\n");

        $document = (new LegalDocument)->render($page, 'en');

        $this->assertStringContainsString('[[legal.contact_email]]', $document['html']);
    }
}
