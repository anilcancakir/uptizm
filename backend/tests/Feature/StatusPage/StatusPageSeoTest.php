<?php

namespace Tests\Feature\StatusPage;

use App\Enums\DomainMode;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\Services\SitemapBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * What a crawler is told about a public status page: one canonical per language,
 * a reciprocal alternate set, and a sitemap that says the same thing.
 *
 * This surface is the hard case for hreflang, and each property below is a way
 * it fails silently for weeks:
 *
 *  1. ONE CANONICAL PER LANGUAGE. The same document answers on up to three hosts
 *     (`<app>/s/{slug}`, `{slug}.<subdomain_host>`, a `custom_domain`), so
 *     canonicalisation has to be settled BEFORE hreflang means anything. Every
 *     host serves the SAME canonical, the one `StatusPage::publicUrl()`'s
 *     precedence picks, and each language is canonical for itself rather than
 *     for the owner's language. WHICH language gets the unprefixed URL is the
 *     owner's (`status_pages.locale`), so a fixture pinned to the deployment
 *     default cannot see that half at all.
 *  2. RECIPROCITY. Google enforces it: an alternate set that omits its own page,
 *     or that names a host which does not point back, is dropped WHOLESALE, and
 *     the failure is only visible in a search console weeks later. So every
 *     language declares every language, itself included, plus `x-default`.
 *  3. AGREEMENT WITH THE SITEMAP. The alternates are EXTRACTED from the rendered
 *     page and from the sitemap entry and compared TO EACH OTHER. Asserting each
 *     against a list typed here is the test that passes while the two disagree,
 *     which is the specific failure `SitemapBuilder`'s docblock exists to
 *     prevent.
 *  4. MEMBERSHIP. `is_public` alone decides, matching
 *     {@see StatusPage::scopePublic()} and the controller's own 404 gate, so a
 *     URL in the sitemap is exactly a URL that answers 200.
 *
 * The pages here carry no monitors, incidents or maintenance windows on purpose:
 * none of the four properties reads the body, and a fixture that seeded one
 * would suggest they do. `StatusPageLocaleTest` owns the populated fixture and
 * the copy assertions that need it.
 *
 * The subdomain host is `uptizm.test` from `phpunit.xml`, and the app host comes
 * from `APP_URL`; they differ, which is what lets the canonical assertions below
 * tell "the page's own host" apart from "the host the request arrived on".
 */
class StatusPageSeoTest extends TestCase
{
    use RefreshDatabase;

    private const string SLUG = 'acme';

    public function test_each_language_is_canonical_for_itself_and_declares_every_other(): void
    {
        $this->makePage(self::SLUG);

        $english = $this->get('/s/'.self::SLUG)->assertOk()->getContent();
        $turkish = $this->get('/tr/s/'.self::SLUG)->assertOk()->getContent();

        // Self-canonical, both ways. A Turkish page canonicalising to the English
        // one deindexes the Turkish one, and it is the natural mistake here
        // because the page has an owner-chosen language of its own.
        $this->assertSame(url('/s/'.self::SLUG), $this->canonicalOf($english));
        $this->assertSame(url('/tr/s/'.self::SLUG), $this->canonicalOf($turkish));

        $expected = [
            'en' => url('/s/'.self::SLUG),
            'tr' => url('/tr/s/'.self::SLUG),
            // The fallback for a visitor whose language we do not publish in,
            // pointing at THIS page in the default language and never at a root.
            'x-default' => url('/s/'.self::SLUG),
        ];

        // Both languages declare the SAME set, itself included. That is what
        // reciprocity is, and either page declaring only the other is the shape
        // Google discards the cluster for.
        $this->assertSame($expected, $this->alternatesOf($english));
        $this->assertSame($expected, $this->alternatesOf($turkish));
    }

    public function test_a_page_published_in_another_language_owns_the_unprefixed_url_for_it(): void
    {
        /*
         * `status_pages.locale` is the language the UNPREFIXED URL serves, so on
         * this page `/s/{slug}` is the Turkish canonical and English lives at
         * `/en/s/{slug}`. Three of the four layers used to read
         * `app.default_locale` instead, which published an `hreflang="en"`
         * naming a URL that serves Turkish, a canonical (`/tr/s/{slug}`) no
         * route answered, and no reachable English document at all.
         *
         * Both documents are asserted through `<html lang>` as well as through
         * their canonical, so this cannot pass on two copies of one language.
         */
        $this->makePage('turkce', locale: 'tr');

        $turkish = $this->get('/s/turkce')->assertOk()->getContent();
        $english = $this->get('/en/s/turkce')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="tr">', $turkish);
        $this->assertStringContainsString('<html lang="en">', $english);

        $this->assertSame(url('/s/turkce'), $this->canonicalOf($turkish));
        $this->assertSame(url('/en/s/turkce'), $this->canonicalOf($english));

        $expected = [
            'en' => url('/en/s/turkce'),
            'tr' => url('/s/turkce'),
            // The fallback names this page in the language its bare URL serves,
            // which is the owner's and not the deployment's.
            'x-default' => url('/s/turkce'),
        ];

        $this->assertSame($expected, $this->alternatesOf($turkish));
        $this->assertSame($expected, $this->alternatesOf($english));
    }

    public function test_a_pages_own_language_has_no_prefixed_url(): void
    {
        /*
         * The other half of "exactly one URL per language per page". The prefix
         * accepts every supported language, because a route cannot know which
         * page it is about, so the page itself has to refuse the one prefix that
         * duplicates its own bare URL: without this a Turkish page answered
         * Turkish on both `/s/{slug}` and `/tr/s/{slug}` and the duplicate
         * competed with the canonical it declares.
         *
         * A 404 and never a redirect, matching this route's other refusals: on
         * this surface nothing redirects between two languages of one document.
         */
        $this->makePage('turkce', locale: 'tr');
        $this->makePage('ingilizce');

        $this->get('/tr/s/turkce')->assertNotFound();
        $this->get('/en/s/ingilizce')->assertNotFound();

        // The mirror image, so the two 404s above are not passing for the wrong
        // reason: each page's OTHER language does answer at its prefixed URL.
        $this->get('/en/s/turkce')->assertOk();
        $this->get('/tr/s/ingilizce')->assertOk();

        // A language this deployment does not publish in is still not a page, in
        // either direction. That one is refused by the route's `whereIn` rather
        // than by the controller.
        $this->get('/de/s/turkce')->assertNotFound();
        $this->get('/de/s/ingilizce')->assertNotFound();
    }

    public function test_the_canonical_link_carries_no_hreflang(): void
    {
        /*
         * Google ignores a `rel="canonical"` that carries an `hreflang`, which
         * leaves the document with NO canonical at all: strictly worse than the
         * three-host duplicate it was added to resolve. The tag is matched whole
         * rather than the attribute grepped for, because `hreflang` appears
         * legitimately on every alternate a line below.
         */
        $this->makePage(self::SLUG);

        $html = $this->get('/s/'.self::SLUG)->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<link rel="canonical"[^>]*>/', (string) $html, $matches));
        $this->assertStringNotContainsString('hreflang', $matches[0]);
    }

    public function test_the_subdomain_and_path_forms_agree_on_one_canonical(): void
    {
        // Subdomain mode: the page's own host wins, so BOTH URLs that resolve
        // must name it. Two hosts serving byte-identical content and each naming
        // itself is a customer's page competing with itself.
        $this->makePage(self::SLUG, mode: DomainMode::Subdomain);

        $viaPath = $this->get('/s/'.self::SLUG)->assertOk()->getContent();
        $viaSubdomain = $this->get('http://'.self::SLUG.'.uptizm.test/')->assertOk()->getContent();

        $canonical = 'http://'.self::SLUG.'.uptizm.test/';

        $this->assertSame($canonical, $this->canonicalOf($viaPath));
        $this->assertSame($canonical, $this->canonicalOf($viaSubdomain));

        // And the losing host never appears as an alternate of its own: an
        // alternate that does not point back breaks reciprocity for the cluster.
        foreach ([$viaPath, $viaSubdomain] as $html) {
            $this->assertSame(
                [
                    'en' => $canonical,
                    'tr' => 'http://'.self::SLUG.'.uptizm.test/tr',
                    'x-default' => $canonical,
                ],
                $this->alternatesOf($html),
            );
        }
    }

    public function test_the_sitemap_alternates_are_the_ones_the_page_itself_emits(): void
    {
        /*
         * The whole point of composing both from one `StatusPageChrome`. Each set
         * is EXTRACTED from its own document and compared to the other, so a
         * change to the locale-path scheme, to the hostname precedence, or to
         * either renderer moves both or fails here. Checking each against a list
         * typed in this file would pass while both were wrong together.
         *
         * Both addressing modes are covered, because the path form is the only
         * one `SitemapBuilder::entry()` could ever have expressed: a subdomain
         * page compared this way is what proves the absolute-URL entry point
         * carries the page's own host into the XML.
         *
         * The third fixture is a page published in a NON-default language, and it
         * is the one the `loc` assertion below exists for. The builder selected
         * only the addressing columns, so the chrome could not see `locale` at
         * all and composed the whole entry in the deployment default: the
         * alternate SETS still agreed (they are language-independent by
         * construction) while the `loc` named a URL whose own document
         * canonicalises somewhere else, which is a self-contradicting entry the
         * set comparison alone cannot see.
         */
        $this->makePage('path-mode');
        $this->makePage('subdomain-mode', mode: DomainMode::Subdomain);
        $this->makePage('turkce', locale: 'tr');

        $urls = $this->urls($this->get('/'.SitemapBuilder::STATUS_PAGES_PATH)->assertOk()->getContent());

        $pages = [
            url('/s/path-mode') => '/s/path-mode',
            'http://subdomain-mode.uptizm.test/' => 'http://subdomain-mode.uptizm.test/',
            url('/s/turkce') => '/s/turkce',
        ];

        foreach ($pages as $loc => $request) {
            $this->assertArrayHasKey($loc, $urls, "The status-page segment does not list {$loc}.");

            $html = (string) $this->get($request)->assertOk()->getContent();

            $fromPage = $this->alternatesOf($html);
            $fromSitemap = $urls[$loc]['alternates'];

            $this->assertNotSame([], $fromPage, "GET {$request} emitted no hreflang at all, so this comparison checks nothing.");

            ksort($fromPage);
            ksort($fromSitemap);

            $this->assertSame($fromPage, $fromSitemap, "The sitemap and {$request} disagree about this page's alternates.");

            // The `loc` is the document's OWN canonical. A sitemap that lists a
            // URL the page canonicalises away from asks Google to index one URL
            // and follow another, and no comparison of the alternate sets can
            // catch it, because those sets are the same set whichever language
            // composed them.
            $this->assertSame(
                $this->canonicalOf($html),
                $loc,
                "The sitemap lists {$loc}, which is not the canonical that document declares.",
            );
        }
    }

    public function test_every_url_in_the_status_page_segment_answers(): void
    {
        // A sitemap entry with no page behind it is a crawl error handed to
        // Google on purpose. Every `loc` AND every alternate is requested,
        // because the alternates are the URLs a crawler actually follows.
        //
        // The second page is published in a non-default language, which is where
        // this used to break: its English alternate was `/s/{slug}` (a Turkish
        // document) and no route answered `/en/s/{slug}` at all.
        $this->makePage(self::SLUG);
        $this->makePage('turkce', locale: 'tr');

        $urls = $this->urls($this->get('/'.SitemapBuilder::STATUS_PAGES_PATH)->assertOk()->getContent());

        $this->assertNotSame([], $urls);

        foreach ($urls as $loc => $entry) {
            $this->get($loc)->assertOk();

            foreach ($entry['alternates'] as $href) {
                $this->get($href)->assertOk();
            }

            // No `lastmod`: a status page is redrawn by every check, so no stamp
            // on the row describes the document a crawler fetched, and an
            // untrustworthy one is discounted sitewide.
            $this->assertNull($entry['lastmod'], "{$loc} carries a lastmod nothing stands behind.");
        }
    }

    public function test_a_private_page_appears_in_no_sitemap(): void
    {
        // `is_public` alone, matching `StatusPage::scopePublic()` and the
        // controller's 404 gate. The public page beside it is the positive
        // control: without it this would pass on an empty document.
        $this->makePage('hidden', isPublic: false);
        $this->makePage('shown');

        foreach ([SitemapBuilder::MARKETING_PATH, SitemapBuilder::SERVICES_PATH, SitemapBuilder::STATUS_PAGES_PATH] as $segment) {
            $xml = (string) $this->get('/'.$segment)->assertOk()->getContent();

            $this->assertStringNotContainsString('hidden', $xml, "A private page reached {$segment}.");
        }

        $this->assertArrayHasKey(
            url('/s/shown'),
            $this->urls($this->get('/'.SitemapBuilder::STATUS_PAGES_PATH)->assertOk()->getContent()),
        );
    }

    public function test_a_page_with_subscriptions_disabled_is_still_listed(): void
    {
        /*
         * `subscriptions_enabled` governs whether ONE FORM renders; it is not an
         * indexability flag. Excluding on it would drop a page that answers 200
         * and whose own head declares a canonical and a full alternate set,
         * which is a one-sided reciprocity break rather than a privacy measure.
         */
        $this->makePage(self::SLUG, subscriptions: false);

        $this->assertArrayHasKey(
            url('/s/'.self::SLUG),
            $this->urls($this->get('/'.SitemapBuilder::STATUS_PAGES_PATH)->assertOk()->getContent()),
        );
    }

    public function test_the_index_names_the_status_page_segment(): void
    {
        // Named even with no public page in the database: an empty `<urlset>` is
        // a valid document, while dropping the segment when the last page is
        // unpublished would throw away its Search Console history.
        $xml = new SimpleXMLElement((string) $this->get('/'.SitemapBuilder::INDEX_PATH)->assertOk()->getContent());

        $children = [];

        foreach ($xml->sitemap as $sitemap) {
            $children[] = (string) $sitemap->loc;
        }

        $this->assertContains(url(SitemapBuilder::STATUS_PAGES_PATH), $children);
    }

    /**
     * The `hreflang => href` pairs a rendered page declares in its head,
     * `x-default` included.
     *
     * Deliberately the same expression `Tests\Feature\Marketing\SitemapTest`
     * reads the marketing pages with, copied rather than extracted: it is private
     * there, the two suites measure two independent surfaces, and a shared helper
     * that changed would move both sides of a comparison whose whole value is
     * that the two sides are independent.
     *
     * Attribute ORDER is what makes it safe: the language switcher writes `href`
     * before `hreflang` on its anchors, so only the `<link rel="alternate">` tags
     * match.
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
     * The one canonical URL a rendered page names.
     */
    private function canonicalOf(string $html): string
    {
        $this->assertSame(1, preg_match('/<link rel="canonical" href="([^"]+)">/', $html, $matches));

        return $matches[1];
    }

    /**
     * The url entries of a `<urlset>`, keyed by `loc`.
     *
     * `->attributes()` and NOT `$link['hreflang']`: an element reached through
     * `children($namespace)` keeps that namespace active for attribute lookups
     * too, and these attributes carry no namespace at all, so the direct form
     * silently reads an empty string for every one of them and the comparison
     * above would pass over nothing.
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
     * A published page with no content on it, on its own team.
     *
     * `locale` defaults to null, the deployment default and still the common
     * shape, so most cases here read the same URL space every page had before the
     * column existed. It is a PARAMETER because null was once hardcoded, and that
     * is why three layers shipped reading `app.default_locale` as the unprefixed
     * language: with the owner's choice always equal to the deployment's, no
     * assertion in this file could tell the two apart.
     */
    private function makePage(
        string $slug,
        bool $isPublic = true,
        bool $subscriptions = true,
        DomainMode $mode = DomainMode::Path,
        ?string $locale = null,
    ): StatusPage {
        $user = User::query()->create([
            'name' => 'Seo Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Seo Team',
        ]);

        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'domain_mode' => $mode,
            'is_public' => $isPublic,
            'subscriptions_enabled' => $subscriptions,
            'locale' => $locale,
        ]);
    }
}
