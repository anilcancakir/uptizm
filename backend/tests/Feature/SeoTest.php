<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The crawler-facing surface of the apex host: meta, Open Graph, the Twitter
 * card, JSON-LD, robots.txt and the sitemap.
 *
 * Most of these guard against a silent failure rather than a visible one. Nothing
 * on the page looks wrong when the canonical names the API hostname, when the
 * social card points at an image nobody can fetch, or when the structured data
 * quietly falls back to the package's own defaults, which is exactly why they are
 * pinned here.
 */
class SeoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://uptizm.test']);
    }

    public function test_the_title_and_description_are_present_and_specific(): void
    {
        $response = $this->get('/');

        $response->assertSee('<title>Uptizm: uptime, incident and status-page monitoring</title>', escape: false);
        $response->assertSee('name="description"', escape: false);
        $response->assertSee('refuses to guess', escape: false);
    }

    public function test_the_canonical_names_the_configured_host_not_the_requested_one(): void
    {
        /*
         * The landing route carries no host constraint, so it also answers on the
         * server's bare address and on any other name pointed at this box. A
         * canonical derived from the request would let a crawler that arrived by
         * one of those index the page there as a competing copy.
         *
         * The host here is deliberately NOT `api.uptizm.test`: with
         * `status_pages.subdomain_host` configured, every `*.uptizm.test` name is
         * claimed by the status-page route, and `api` is a reserved label there,
         * so that host 404s instead (see the test below).
         */
        $response = $this->get('http://198.51.100.7/');

        $response->assertOk();
        $response->assertSee('<link rel="canonical" href="https://uptizm.test/">', escape: false);
        $response->assertDontSee('rel="canonical" href="http://198.51.100.7', escape: false);
    }

    public function test_a_reserved_subdomain_does_not_serve_the_landing_page(): void
    {
        // Discovered while writing the test above, and worth pinning: the API
        // hostname does not fall through to the marketing page. Every
        // `*.uptizm.test` name belongs to the status-page route, and `api` is a
        // reserved label there, so it is refused before any database lookup.
        $this->get('http://api.uptizm.test/')->assertNotFound();
    }

    public function test_the_robots_directive_allows_indexing(): void
    {
        $this->get('/')->assertSee('name="robots" content="index, follow', escape: false);
    }

    public function test_the_social_card_is_absolute_on_the_canonical_host(): void
    {
        // A relative or request-derived image URL is the most common way a social
        // card ends up blank in someone else's Slack.
        $response = $this->get('http://198.51.100.7/');

        $response->assertSee('property="og:image" content="https://uptizm.test/brand/og-image.png"', escape: false);
        $response->assertSee('property="og:image:width" content="1200"', escape: false);
        $response->assertSee('property="og:image:height" content="630"', escape: false);
        $response->assertSee('name="twitter:card" content="summary_large_image"', escape: false);
        $response->assertSee('name="twitter:image" content="https://uptizm.test/brand/og-image.png"', escape: false);
    }

    public function test_the_structured_data_describes_the_application(): void
    {
        /*
         * Regression, and a subtle one: `JsonLd` and `JsonLdMulti` are two
         * separate container singletons, and `SEOTools::generate()` renders the
         * MULTI one. Setting the type on the other compiles, runs, and emits the
         * config default instead, so this shipped as `@type: WebPage` while the
         * controller plainly asked for a SoftwareApplication.
         */
        $data = $this->structuredData();

        $this->assertSame('SoftwareApplication', $data['@type']);
        $this->assertSame('Uptizm', $data['name']);
        $this->assertSame('https://uptizm.test/', $data['url']);
        $this->assertSame('DeveloperApplication', $data['applicationCategory']);
        $this->assertSame('Organization', $data['publisher']['@type']);
    }

    public function test_the_structured_data_claims_nothing_we_cannot_back(): void
    {
        // A fabricated rating buys a star in a search result and is the exact
        // dishonesty the rest of this page is built to avoid. The only offer is
        // the free tier, which is real and self-serve; paid tiers stay out until
        // a checkout can complete.
        $data = $this->structuredData();

        $this->assertArrayNotHasKey('aggregateRating', $data);
        $this->assertArrayNotHasKey('review', $data);
        $this->assertSame('0', $data['offers']['price']);
    }

    public function test_robots_txt_is_served_and_derives_the_sitemap_from_config(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('Sitemap: https://uptizm.test/sitemap.xml', escape: false);
        $response->assertSee('Disallow: /api/', escape: false);
    }

    public function test_robots_txt_keeps_status_pages_crawlable(): void
    {
        // Tenant status pages are meant to be findable. One robots.txt answers for
        // every hostname sharing this document root, so a blanket `Disallow: /`
        // would deindex every customer's status page along with the API.
        $this->get('/robots.txt')
            ->assertSee('Allow: /', escape: false)
            ->assertDontSee("Disallow: /\n", escape: false);
    }

    public function test_the_sitemap_is_valid_xml_naming_the_canonical_host(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml, 'The sitemap is not parseable XML.');
        $this->assertSame('https://uptizm.test/', (string) $xml->url[0]->loc);
    }

    public function test_the_sitemap_does_not_publish_the_customer_list(): void
    {
        // Enumerating every /s/{slug} would hand anyone our customer list in a
        // single file, and would list pages whose owners chose to keep them
        // private. A tenant's page gets discovered from the tenant's own site.
        $this->get('/sitemap.xml')->assertDontSee('/s/', escape: false);
    }

    public function test_the_page_links_a_complete_icon_set(): void
    {
        $response = $this->get('/');

        $response->assertSee('rel="icon" href="'.asset('favicon.ico').'"', escape: false);
        $response->assertSee('href="'.asset('favicon.svg').'"', escape: false);
        $response->assertSee('rel="apple-touch-icon"', escape: false);
        $response->assertSee('rel="manifest"', escape: false);
        $response->assertSee('name="theme-color"', escape: false);
    }

    public function test_the_icon_files_actually_exist_and_are_not_empty(): void
    {
        // `public/favicon.ico` shipped as a ZERO BYTE file for months. That is
        // worse than a missing one: it answers 200, so the browser renders a
        // broken image instead of falling back to the SVG.
        foreach ([
            'favicon.ico',
            'favicon.svg',
            'apple-touch-icon.png',
            'site.webmanifest',
            'brand/og-image.png',
            'brand/icon-192.png',
            'brand/icon-512.png',
            'brand/icon-maskable-192.png',
            'brand/icon-maskable-512.png',
        ] as $file) {
            $path = public_path($file);

            $this->assertFileExists($path);
            $this->assertGreaterThan(100, filesize($path), "{$file} is suspiciously small.");
        }
    }

    public function test_the_manifest_is_valid_and_does_not_claim_to_be_an_app(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true);

        $this->assertIsArray($manifest);
        $this->assertSame('Uptizm', $manifest['name']);

        // `standalone` would let someone install the MARKETING page as an app.
        // The installable product is the Flutter client on its own host, which
        // has its own manifest in web/manifest.json.
        $this->assertSame('browser', $manifest['display']);

        $purposes = array_column($manifest['icons'], 'purpose');
        $this->assertContains('maskable', $purposes, 'Android crops a non-maskable icon.');
    }

    public function test_the_hero_font_is_preloaded(): void
    {
        // The hero headline is the largest contentful paint. Only the sans face is
        // preloaded; the mono face carries numbers and can arrive later.
        $response = $this->get('/');

        $response->assertSee('rel="preload"', escape: false);
        $response->assertSee('as="font"', escape: false);
        $response->assertSee('GeistVariable', escape: false);
    }

    /**
     * The page's JSON-LD, decoded.
     *
     * @return array<string, mixed>
     */
    protected function structuredData(): array
    {
        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression(
            '#<script type="application/ld\+json">#',
            (string) $html,
            'The page emitted no JSON-LD block.',
        );

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', (string) $html, $matches);

        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded, 'The JSON-LD block is not valid JSON.');

        return $decoded;
    }
}
