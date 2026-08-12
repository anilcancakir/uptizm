<?php

namespace Tests\Unit\Support\StatusPages;

use App\Enums\DomainMode;
use App\Models\StatusPage;
use App\Support\StatusPages\StatusPageChrome;
use Tests\TestCase;

/**
 * One canonical per language, on ONE hostname, in all three addressing modes.
 *
 * The same status page answers on up to three hosts, so canonicalisation has to
 * be settled before hreflang means anything: an alternate pointing at a hostname
 * that is not canonical for its language breaks reciprocity, and Google then
 * ignores the whole cluster rather than the one bad entry. That failure is
 * silent and surfaces weeks later in a search console, which is why it is pinned
 * here rather than left to the rendered page.
 *
 * No database: the chrome reads four columns and two config values, and every
 * URL it emits is composed from configuration rather than from a request. The
 * fourth column is `locale`, which decides which language gets no prefix, so a
 * fixture that omitted it would only ever exercise the app-default case.
 */
class StatusPageChromeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://uptizm.test',
            'app.default_locale' => 'en',
            'magic-starter.supported_locales' => ['en', 'tr'],
            'status_pages.subdomain_host' => 'status.uptizm.test',
        ]);
    }

    public function test_the_path_form_carries_the_language_in_front_of_the_slug(): void
    {
        $page = $this->page(DomainMode::Path);

        $this->assertSame(
            'https://uptizm.test/s/acme',
            (new StatusPageChrome($page, 'en'))->toArray()['canonicalUrl'],
        );

        $this->assertSame(
            'https://uptizm.test/tr/s/acme',
            (new StatusPageChrome($page, 'tr'))->toArray()['canonicalUrl'],
        );
    }

    public function test_a_subdomain_page_is_canonical_on_its_subdomain_and_never_on_the_path_form(): void
    {
        $page = $this->page(DomainMode::Subdomain);

        $english = (new StatusPageChrome($page, 'en'))->toArray();
        $turkish = (new StatusPageChrome($page, 'tr'))->toArray();

        $this->assertSame('https://acme.status.uptizm.test/', $english['canonicalUrl']);
        $this->assertSame('https://acme.status.uptizm.test/tr', $turkish['canonicalUrl']);

        // The losing hostname points AT the winner through the canonical above;
        // it must not also appear as an alternate, which would tell a crawler the
        // language has two addresses.
        foreach ([$english, $turkish] as $chrome) {
            foreach ($chrome['alternates'] as $alternate) {
                $this->assertStringNotContainsString(
                    '/s/acme',
                    $alternate['href'],
                    'The path form appeared as an alternate of a page canonical on its subdomain.',
                );
            }
        }
    }

    public function test_a_custom_domain_page_is_canonical_on_its_own_domain(): void
    {
        $page = $this->page(DomainMode::Custom, 'status.acme.com');

        $this->assertSame(
            'https://status.acme.com/',
            (new StatusPageChrome($page, 'en'))->toArray()['canonicalUrl'],
        );

        $this->assertSame(
            'https://status.acme.com/tr',
            (new StatusPageChrome($page, 'tr'))->toArray()['canonicalUrl'],
        );
    }

    public function test_a_mode_whose_prerequisite_is_missing_falls_back_to_the_path_form(): void
    {
        // A Custom page with no domain and a Subdomain page in a deployment that
        // configures no subdomain host both have to resolve somewhere, and the
        // path form is the one address that always answers.
        config(['status_pages.subdomain_host' => null]);

        $this->assertSame(
            'https://uptizm.test/tr/s/acme',
            (new StatusPageChrome($this->page(DomainMode::Subdomain), 'tr'))->toArray()['canonicalUrl'],
        );

        $this->assertSame(
            'https://uptizm.test/tr/s/acme',
            (new StatusPageChrome($this->page(DomainMode::Custom), 'tr'))->toArray()['canonicalUrl'],
        );
    }

    public function test_the_default_language_keeps_the_url_the_page_already_published(): void
    {
        // The canonical for the default language is the address customers have
        // already shared, so it must be byte-identical to what the model has
        // always answered. This is what stops a language prefix from silently
        // moving every existing page.
        foreach ([DomainMode::Path, DomainMode::Subdomain, DomainMode::Custom] as $mode) {
            $page = $this->page($mode, 'status.acme.com');

            $this->assertSame(
                $page->publicUrl(),
                (new StatusPageChrome($page, 'en'))->toArray()['canonicalUrl'],
                "The default language's canonical drifted from publicUrl() in [{$mode->value}] mode.",
            );
        }
    }

    public function test_every_language_declares_itself_and_every_other_plus_x_default(): void
    {
        foreach ([DomainMode::Path, DomainMode::Subdomain, DomainMode::Custom] as $mode) {
            $page = $this->page($mode, 'status.acme.com');

            foreach (['en', 'tr'] as $rendering) {
                $chrome = (new StatusPageChrome($page, $rendering))->toArray();

                $hreflangs = array_column($chrome['alternates'], 'hreflang');
                $byHreflang = array_column($chrome['alternates'], 'href', 'hreflang');

                $this->assertSame(['en', 'tr', 'x-default'], $hreflangs);

                // Self-referencing: the language being rendered declares itself,
                // and the entry it declares is the canonical it just emitted. An
                // alternate set that omits the page it is on reads as broken
                // reciprocity and is ignored wholesale.
                $this->assertSame($chrome['canonicalUrl'], $byHreflang[$rendering]);

                // `x-default` points at THIS page in the language its
                // unprefixed URL serves, never at a site root.
                $this->assertSame($chrome['canonicalLocaleUrl'], $byHreflang['x-default']);
                $this->assertSame($byHreflang['en'], $byHreflang['x-default']);

                // Exactly one canonical per language: the two languages do not
                // share an address, and neither has a second one.
                $this->assertNotSame($byHreflang['en'], $byHreflang['tr']);
            }
        }
    }

    public function test_the_pages_own_language_owns_the_unprefixed_url_and_the_app_default_takes_a_prefix(): void
    {
        /*
         * `status_pages.locale` means "the language the unprefixed URL serves",
         * so WHICH language is unprefixed is a per-PAGE fact and never the
         * deployment default. On a page published in Turkish, `/s/acme` IS the
         * Turkish canonical and English needs `/en/s/acme`.
         *
         * Reading `app.default_locale` here published `hreflang="en"` pointing
         * at a URL that serves Turkish and a canonical (`/tr/s/acme`) that no
         * route answered, which is the shape Google drops the whole cluster for.
         */
        $page = $this->page(DomainMode::Path, locale: 'tr');

        $turkish = (new StatusPageChrome($page, 'tr'))->toArray();
        $english = (new StatusPageChrome($page, 'en'))->toArray();

        $this->assertSame('https://uptizm.test/s/acme', $turkish['canonicalUrl']);
        $this->assertSame('https://uptizm.test/en/s/acme', $english['canonicalUrl']);

        foreach (['tr' => $turkish, 'en' => $english] as $rendering => $chrome) {
            $byHreflang = array_column($chrome['alternates'], 'href', 'hreflang');

            // The unprefixed URL, which is the `loc` the sitemap publishes and
            // the fallback a crawler follows for a language we do not speak.
            $this->assertSame('https://uptizm.test/s/acme', $chrome['canonicalLocaleUrl']);
            $this->assertSame($chrome['canonicalLocaleUrl'], $byHreflang['x-default']);

            // Still self-referencing, and still exactly one URL per language.
            $this->assertSame($chrome['canonicalUrl'], $byHreflang[$rendering]);
            $this->assertSame('https://uptizm.test/s/acme', $byHreflang['tr']);
            $this->assertSame('https://uptizm.test/en/s/acme', $byHreflang['en']);
        }

        // Every switcher link points at the URL that actually serves its own
        // language, which is what the label beside it promises.
        $this->assertSame(
            [
                'en' => '/en/s/acme',
                'tr' => '/s/acme',
            ],
            array_column($turkish['localeLinks'], 'path', 'code'),
        );
    }

    public function test_a_subdomain_page_published_in_another_language_puts_that_language_at_its_root(): void
    {
        // The prefix goes in front of whichever hostname the page is canonical
        // on, so the per-page rule has to hold on the subdomain form too: the
        // root serves the page's own language and the app default is a segment.
        $chrome = (new StatusPageChrome($this->page(DomainMode::Subdomain, locale: 'tr'), 'tr'))->toArray();

        $this->assertSame('https://acme.status.uptizm.test/', $chrome['canonicalUrl']);
        $this->assertSame(
            [
                'en' => '/en',
                'tr' => '/',
            ],
            array_column($chrome['localeLinks'], 'path', 'code'),
        );
    }

    public function test_the_switcher_enumerates_the_same_languages_the_offer_negotiates_against(): void
    {
        /*
         * One accessor on both sides, and this is why: `StatusPageLocale`
         * PREPENDS `app.default_locale` to the supported list, so a deployment
         * whose default is absent from `magic-starter.supported_locales` could
         * offer a visitor a language the switcher had no link for. The banner
         * dereferences that link unguarded, so the divergence is a 500 on a
         * PUBLIC page rather than a missing anchor.
         */
        config([
            'app.default_locale' => 'de',
            'magic-starter.supported_locales' => ['en', 'tr'],
        ]);

        $links = (new StatusPageChrome($this->page(DomainMode::Path), 'de'))->toArray()['localeLinks'];

        $this->assertSame(['de', 'en', 'tr'], array_column($links, 'code'));

        // And a null-locale page still falls back to the deployment default, so
        // German is the language on the unprefixed URL here.
        $this->assertSame(
            [
                'de' => '/s/acme',
                'en' => '/en/s/acme',
                'tr' => '/tr/s/acme',
            ],
            array_column($links, 'path', 'code'),
        );
    }

    public function test_the_switcher_links_name_every_language_in_its_own_words(): void
    {
        $links = (new StatusPageChrome($this->page(DomainMode::Path), 'tr'))->toArray()['localeLinks'];

        $this->assertSame(['en', 'tr'], array_column($links, 'code'));

        // Endonyms, from ICU rather than a typed map: somebody hunting for
        // Turkish scans for "Türkçe", not for "Turkish".
        $this->assertSame(['English', 'Türkçe'], array_column($links, 'label'));

        // The path is the same-host relative form of the same document the
        // absolute `url` names, so a no-JS switcher can link within the hostname
        // it was rendered on.
        $this->assertSame(['/s/acme', '/tr/s/acme'], array_column($links, 'path'));
        $this->assertSame(
            ['https://uptizm.test/s/acme', 'https://uptizm.test/tr/s/acme'],
            array_column($links, 'url'),
        );

        // `current` follows the language being RENDERED, not the page's own, so
        // a page whose owner publishes in English still marks Turkish current on
        // its Turkish URL.
        $this->assertSame([false, true], array_column($links, 'current'));
    }

    public function test_a_subdomain_switcher_links_within_its_own_hostname(): void
    {
        $links = (new StatusPageChrome($this->page(DomainMode::Subdomain), 'en'))->toArray()['localeLinks'];

        $this->assertSame(['/', '/tr'], array_column($links, 'path'));
        $this->assertSame(
            ['https://acme.status.uptizm.test/', 'https://acme.status.uptizm.test/tr'],
            array_column($links, 'url'),
        );
    }

    /**
     * An unsaved page: the chrome reads columns and configuration only, so a row
     * would add a database round trip and prove nothing extra.
     *
     * `locale` defaults to null, the shape every page had before the column
     * existed and still the common one: the deployment default then owns the
     * unprefixed URL.
     */
    protected function page(DomainMode $mode, ?string $customDomain = null, ?string $locale = null): StatusPage
    {
        return new StatusPage([
            'name' => 'Acme Status',
            'slug' => 'acme',
            'domain_mode' => $mode,
            'custom_domain' => $customDomain,
            'locale' => $locale,
        ]);
    }
}
