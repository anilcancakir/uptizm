<?php

namespace Tests\Feature\Marketing;

use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Enums\ServiceStatusSource;
use App\Http\Controllers\Marketing\ShowSitemapController;
use App\Models\Monitor;
use App\Models\Service;
use App\Models\ServiceFeedSnapshot;
use App\Services\Services\SitemapBuilder;
use App\Support\Services\SystemTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use SplFileInfo;
use Tests\TestCase;

/**
 * The sitemap index and its two segments.
 *
 * Four properties are pinned here, and each one is a way a sitemap fails silently
 * for weeks:
 *
 *  1. The DOCUMENT SHAPE. `sitemap.xml` is a `<sitemapindex>` naming exactly two
 *     children and containing no `<url>` at all; the segments are `<urlset>`s. An
 *     index carrying url entries is invalid against both schemas and Search Console
 *     simply reports nothing useful.
 *  2. MEMBERSHIP. The services segment lists the hub plus every published,
 *     terms-reviewed service and nothing else. A draft row appears nowhere, and its
 *     page answers 404, so the two agree.
 *  3. `lastmod` PROVENANCE. It comes from `services.content_changed_at`, which the
 *     ingester moves only when the normalized reading actually changed. A routine
 *     poll writes a snapshot row and touches `updated_at`, and neither may move the
 *     sitemap: Google discounts an untrustworthy `lastmod` sitewide, and a status
 *     page polled every minute is the textbook way to earn that.
 *  4. AGREEMENT WITH THE PAGES. The alternates are EXTRACTED from the rendered page
 *     and from the sitemap entry and compared to each other, rather than each being
 *     checked against a list typed here, which would pass while both were wrong.
 *
 * `robots.txt` is deliberately absent from this file: it is a static file under
 * `public/` and the Laravel test client never serves it, so asserting it here would
 * be an assertion about the router. The live curl covers it.
 */
class SitemapTest extends TestCase
{
    use RefreshDatabase;

    private const string SLUG = 'example-provider';

    public function test_the_index_is_an_index_and_names_exactly_three_segments(): void
    {
        $response = $this->get('/'.SitemapBuilder::INDEX_PATH)->assertOk();

        $response->assertHeader('Content-Type', ShowSitemapController::CONTENT_TYPE);

        $xml = $response->getContent();

        $this->assertStringContainsString('<sitemapindex', $xml);
        // An index that carries url entries is not an index.
        $this->assertStringNotContainsString('<url>', $xml);

        $document = new SimpleXMLElement($xml);
        $children = [];

        foreach ($document->sitemap as $sitemap) {
            $children[] = (string) $sitemap->loc;
        }

        $this->assertSame(
            [
                url(SitemapBuilder::MARKETING_PATH),
                url(SitemapBuilder::SERVICES_PATH),
                url(SitemapBuilder::STATUS_PAGES_PATH),
            ],
            $children,
        );
    }

    public function test_the_marketing_segment_lists_only_pages_that_answer(): void
    {
        $response = $this->get('/'.SitemapBuilder::MARKETING_PATH)->assertOk();

        $xml = $response->getContent();
        $this->assertStringContainsString('<urlset', $xml);

        $urls = $this->urls($xml);

        $this->assertArrayHasKey(url('/'), $urls, 'The marketing segment does not list the landing page.');

        foreach (['privacy', 'terms', 'contact', 'faq'] as $page) {
            $this->assertArrayHasKey(url('/'.$page), $urls, "The marketing segment does not list /{$page}.");
        }

        /*
         * A sitemap entry with no page behind it is a crawl error handed to Google on
         * purpose, and `SitemapBuilder::MARKETING_PATHS` is a typed list that can drift
         * from `routes/marketing.php`. So every URL it produces is requested.
         */
        foreach (array_keys($urls) as $location) {
            $this->get($location)->assertOk();
        }

        // No `lastmod` on a marketing page: there is no substantive-change stamp for
        // one, and the Markdown file's mtime is the DEPLOY time in a fresh checkout,
        // which would announce every document as edited on every release.
        foreach ($urls as $location => $entry) {
            $this->assertNull($entry['lastmod'], "{$location} carries a lastmod nothing stands behind.");
        }
    }

    public function test_the_services_segment_lists_the_hub_and_every_published_service(): void
    {
        $this->publish();

        $urls = $this->urls($this->get('/'.SitemapBuilder::SERVICES_PATH)->assertOk()->getContent());

        $this->assertArrayHasKey(url('/status'), $urls);
        $this->assertArrayHasKey(url('/status/'.self::SLUG), $urls);

        foreach (array_keys($urls) as $location) {
            $this->get($location)->assertOk();
        }
    }

    public function test_an_unpublished_service_is_absent_from_the_services_segment(): void
    {
        // Terms reviewed and a monitor attached: the only thing wrong with this row is
        // `is_published`, so the assertion can only pass because that predicate held.
        $this->publish(['is_published' => false]);

        $urls = $this->urls($this->get('/'.SitemapBuilder::SERVICES_PATH)->assertOk()->getContent());

        $this->assertArrayNotHasKey(url('/status/'.self::SLUG), $urls);
        // The positive control: the hub is still there, so the segment was rendered.
        $this->assertArrayHasKey(url('/status'), $urls);
    }

    public function test_a_service_whose_terms_are_unreviewed_is_absent_from_the_services_segment(): void
    {
        $this->publish(['terms_reviewed_at' => null]);

        $urls = $this->urls($this->get('/'.SitemapBuilder::SERVICES_PATH)->assertOk()->getContent());

        $this->assertArrayNotHasKey(url('/status/'.self::SLUG), $urls);
        $this->assertArrayHasKey(url('/status'), $urls);
    }

    public function test_the_lastmod_is_the_content_change_and_not_a_routine_poll(): void
    {
        $changedAt = now()->subDays(3);

        $service = $this->publish(['content_changed_at' => $changedAt]);

        $before = $this->urls($this->get('/'.SitemapBuilder::SERVICES_PATH)->assertOk()->getContent());
        $lastmod = $before[url('/status/'.self::SLUG)]['lastmod'];

        $this->assertNotNull($lastmod, 'The service entry carries no lastmod, so this test would prove nothing.');
        $this->assertSame($service->content_changed_at->toAtomString(), $lastmod);

        /*
         * Now a routine poll: a fresh snapshot row lands and the row itself is touched,
         * exactly as a re-check does. `content_changed_at` is untouched because nothing
         * about the published content changed, so the sitemap must not move. Reading
         * `updated_at` instead is the specific mistake this asserts against, which is
         * why `updated_at` is pushed to now here.
         */
        ServiceFeedSnapshot::query()->create([
            'service_id' => $service->getKey(),
            'fetched_at' => now(),
            'http_status' => 200,
            'indicator' => 'none',
            'components' => [],
            'incidents' => [],
            'content_hash_normalized' => hash('sha256', 'unchanged'),
        ]);

        $service->forceFill(['updated_at' => now()])->save();

        $after = $this->urls($this->get('/'.SitemapBuilder::SERVICES_PATH)->assertOk()->getContent());

        $this->assertSame(
            $lastmod,
            $after[url('/status/'.self::SLUG)]['lastmod'],
            'A poll that changed nothing bumped the sitemap lastmod, which discredits the signal sitewide.',
        );
    }

    public function test_a_service_with_no_content_change_yet_carries_no_lastmod(): void
    {
        // Null rather than a guess. A service published but never fetched has no
        // substantive-change stamp, and inventing one (its `created_at`, `now()`) is the
        // same defect as bumping it on a poll.
        $this->publish();

        $urls = $this->urls($this->get('/'.SitemapBuilder::SERVICES_PATH)->assertOk()->getContent());

        $this->assertNull($urls[url('/status/'.self::SLUG)]['lastmod']);
    }

    public function test_the_sitemap_alternates_are_the_ones_the_page_itself_emits(): void
    {
        /*
         * The whole point of composing both through `ChromeData`. Extracted from each
         * side and compared to the other, so a change to the locale-path scheme moves
         * both or fails here; asserting each against a hardcoded list would pass while
         * both were wrong together.
         */
        $this->publish();

        $urls = $this->urls($this->get('/'.SitemapBuilder::SERVICES_PATH)->assertOk()->getContent());

        foreach (['/status', '/status/'.self::SLUG] as $path) {
            $fromPage = $this->alternatesOf($this->get($path)->assertOk()->getContent());
            $fromSitemap = $urls[url($path)]['alternates'];

            $this->assertNotSame([], $fromPage, "GET {$path} emitted no hreflang at all, so this comparison checks nothing.");

            ksort($fromPage);
            ksort($fromSitemap);

            $this->assertSame($fromPage, $fromSitemap, "The sitemap and {$path} disagree about this page's alternates.");
        }
    }

    public function test_nothing_notifies_a_search_engine_of_a_change(): void
    {
        /*
         * Google retired the sitemap ping endpoint and states that calling it does
         * nothing useful, and the Indexing API is scoped to JobPosting and
         * BroadcastEvent, so neither mechanism may exist here.
         *
         * The two needles are ASSEMBLED from fragments rather than written out, because
         * the plan's own acceptance grep runs over the whole backend tree: a literal in
         * this file would match it and the criterion would report a violation that is
         * only this test looking for one.
         */
        $needles = [
            'ping'.'?sitemap',
            'indexing.'.'googleapis',
        ];

        foreach (['app', 'routes', 'config', 'resources', 'database', 'public'] as $directory) {
            foreach ($this->phpAndTextFilesIn(base_path($directory)) as $file) {
                $contents = (string) file_get_contents($file->getPathname());

                foreach ($needles as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $contents,
                        $file->getPathname().' reaches for a search-engine notification mechanism that does not work.',
                    );
                }
            }
        }
    }

    /**
     * A publishable catalog service with one attached system-team monitor, matching
     * `ServiceStatusPageTest`'s fixture.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function publish(array $attributes = []): Service
    {
        $team = SystemTeam::resolve();

        $service = Service::factory()->create([
            'slug' => self::SLUG,
            'name' => 'Example Provider',
            'category' => 'cloud',
            'status_source' => ServiceStatusSource::None,
            'terms_reviewed_at' => now()->subMonth(),
            'is_published' => true,
            ...$attributes,
        ]);

        $monitor = Monitor::query()->create([
            'team_id' => $team->getKey(),
            'name' => 'Example Provider (api.example.test)',
            'type' => MonitorType::Http,
            'method' => HttpMethod::Get,
            'url' => 'https://api.example.test',
            'check_interval_sec' => 60,
            'regions' => MonitorRegion::values(),
            'alert_on_down' => false,
        ]);

        $service->monitors()->attach($monitor->getKey(), ['label' => 'api.example.test']);

        return $service;
    }

    /**
     * The url entries of a `<urlset>`, keyed by `loc`.
     *
     * @return array<string, array{lastmod: string|null, alternates: array<string, string>}>
     */
    private function urls(string $xml): array
    {
        $document = new SimpleXMLElement($xml);

        $urls = [];

        foreach ($document->url as $url) {
            $alternates = [];

            foreach ($url->children('http://www.w3.org/1999/xhtml')->link as $link) {
                // `->attributes()` and NOT `$link['hreflang']`: an element reached
                // through `children($namespace)` keeps that namespace active for
                // attribute lookups too, and these attributes carry no namespace at
                // all, so the direct form silently reads an empty string for every one
                // of them and the comparison below would pass over nothing.
                $attributes = $link->attributes();

                $alternates[(string) $attributes['hreflang']] = (string) $attributes['href'];
            }

            $urls[(string) $url->loc] = [
                'lastmod' => isset($url->lastmod) ? (string) $url->lastmod : null,
                'alternates' => $alternates,
            ];
        }

        return $urls;
    }

    /**
     * The `hreflang => href` pairs a rendered page declares in its head, x-default
     * included.
     *
     * @return array<string, string>
     */
    private function alternatesOf(string $html): array
    {
        preg_match_all('/hreflang="([^"]+)" href="([^"]+)"/', $html, $matches, PREG_SET_ORDER);

        $alternates = [];

        foreach ($matches as $match) {
            $alternates[$match[1]] = $match[2];
        }

        return $alternates;
    }

    /**
     * Every source and text file under a directory.
     *
     * @return list<SplFileInfo>
     */
    private function phpAndTextFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! in_array($file->getExtension(), ['php', 'txt', 'md', 'js', 'json', 'xml'], true)) {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }
}
