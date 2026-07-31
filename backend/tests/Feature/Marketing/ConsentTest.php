<?php

namespace Tests\Feature\Marketing;

use Tests\TestCase;

/**
 * Analytics, and the consent gate in front of it.
 *
 * Two states, and both of them are load-bearing.
 *
 * WITH NO CONTAINER ID the deployment cannot measure anything, so the whole surface is
 * withheld: no banner, no Consent Mode bootstrap, no GTM loader, and no request to a
 * Google host of any kind. That is the state this deployment is in, and it is what keeps
 * `CookieTest`'s claim and the Privacy page's sentence ("these pages store nothing on your
 * device") true. A banner on a site that sets no cookie is a consent question about
 * nothing, which regulators treat as worse than silence.
 *
 * WITH A CONTAINER ID configured the banner appears and, more importantly, the ORDER in
 * the document head is asserted rather than the mere presence of the pieces. Consent Mode
 * v2 is a promise about sequence: `gtag('consent', 'default', ...)` denying every signal
 * has to be evaluated BEFORE `gtm.js` is fetched, or the container runs its tags for the
 * window between the two and the denial arrives after the cookie. Presence alone would
 * pass against exactly that defect, so the assertion compares byte offsets.
 *
 * The one thing PHPUnit cannot see here is what the browser then does with the snippet.
 * The header-level claim (still no `Set-Cookie`, in either state) is asserted below; the
 * live proof is curl against the running server.
 */
class ConsentTest extends TestCase
{
    /**
     * A syntactically valid container id. Never a real one: a test that fires a live
     * container would report this suite's page views as traffic.
     */
    protected const CONTAINER_ID = 'GTM-TEST123';

    /**
     * The two markers whose ORDER is the actual requirement.
     *
     * Matched as substrings of the rendered head. The default block is identified by the
     * gtag call itself rather than by a comment, because a comment can be reworded while
     * the defect (a default block that runs after the loader) stays.
     */
    protected const CONSENT_DEFAULT = "gtag('consent', 'default'";

    protected const GTM_LOADER = 'googletagmanager.com/gtm.js';

    public function test_nothing_analytics_related_reaches_a_visitor_when_no_container_is_configured(): void
    {
        config(['analytics.gtm_container_id' => null]);

        // A landing page and a legal page, in both languages: the gate lives in the shared
        // chrome, so a page that renders the layout through a different controller must be
        // covered too.
        foreach ($this->pages() as $path) {
            $response = $this->get($path)->assertOk();

            foreach ([
                'googletagmanager.com',
                'google-analytics.com',
                'gtm.js',
                'dataLayer',
                'gtag',
                'id="consent-banner"',
            ] as $absent) {
                $response->assertDontSee($absent, escape: false);
            }
        }
    }

    public function test_the_banner_and_the_bootstrap_arrive_when_a_container_is_configured(): void
    {
        config(['analytics.gtm_container_id' => self::CONTAINER_ID]);

        $response = $this->get('/')->assertOk();

        $response->assertSee('id="consent-banner"', escape: false)
            ->assertSee('role="dialog"', escape: false)
            ->assertSee('aria-labelledby="consent-banner-title"', escape: false)
            ->assertSee('Accept all')
            ->assertSee('Necessary only')
            // Every Consent Mode v2 signal denied up front, plus the window that lets a
            // returning visitor's grant land before the container reads the state.
            ->assertSee("'analytics_storage': 'denied'", escape: false)
            ->assertSee("'ad_storage': 'denied'", escape: false)
            ->assertSee("'ad_user_data': 'denied'", escape: false)
            ->assertSee("'ad_personalization': 'denied'", escape: false)
            ->assertSee("'wait_for_update': 500", escape: false)
            ->assertSee(self::CONTAINER_ID);
    }

    public function test_the_consent_default_block_precedes_the_gtm_loader(): void
    {
        config(['analytics.gtm_container_id' => self::CONTAINER_ID]);

        $html = $this->get('/')->assertOk()->getContent();

        $default = strpos($html, self::CONSENT_DEFAULT);
        $loader = strpos($html, self::GTM_LOADER);

        $this->assertIsInt($default, 'The document carries no consent default block at all.');
        $this->assertIsInt($loader, 'The document loads no container, so the order below proves nothing.');

        $this->assertLessThan(
            $loader,
            $default,
            'gtm.js is requested before every consent signal has been denied, so the container runs '
            .'unconsented for the window between the two.',
        );
    }

    public function test_the_banner_speaks_the_readers_language(): void
    {
        config(['analytics.gtm_container_id' => self::CONTAINER_ID]);

        // Turkish is asserted literally, the way ChromeTest asserts `Giriş yap`: a derived
        // locale code would still leave the STRINGS untested, and an untranslated banner is
        // the failure that matters here.
        $this->get('/tr')
            ->assertOk()
            ->assertSee('Tümünü kabul et')
            ->assertSee('Sadece zorunlu olanlar')
            ->assertSee('Analitik')
            ->assertSee('Zorunlu')
            ->assertDontSee('Accept all')
            ->assertDontSee('Necessary only');
    }

    public function test_analytics_is_never_pre_ticked(): void
    {
        config(['analytics.gtm_container_id' => self::CONTAINER_ID]);

        // The server HTML is what a visitor sees before Alpine boots, so the switch has to
        // be off in the markup and not only in the component's state. Pre-ticked analytics
        // is not consent, it is an assumption of it.
        $this->get('/')
            ->assertOk()
            ->assertSee('aria-checked="false"', escape: false)
            ->assertDontSee('aria-checked="true"', escape: false);
    }

    public function test_the_banner_links_to_the_privacy_notice_in_the_readers_language(): void
    {
        config(['analytics.gtm_container_id' => self::CONTAINER_ID]);

        /*
         * The BANNER'S OWN link, named by its marker attribute, and matched with a pattern
         * because the attributes are formatted one per line. A bare `href="/privacy"`
         * assertion passes on the footer's Legal column alone, so it would prove nothing
         * about the banner: it passed against a banner that did not exist yet.
         *
         * A Turkish reader sent to the English notice cannot read what they are consenting
         * to, which makes the consent uninformed rather than merely inconvenient.
         */
        foreach (['/' => '/privacy', '/tr' => '/tr/privacy'] as $path => $notice) {
            $this->assertMatchesRegularExpression(
                '/data-consent-privacy\s+href="'.preg_quote($notice, '/').'"/',
                (string) $this->get($path)->assertOk()->getContent(),
                "The banner on {$path} does not link to {$notice}.",
            );
        }
    }

    public function test_the_footer_offers_a_withdrawal_only_when_there_is_something_to_withdraw(): void
    {
        // GDPR Art. 7(3): withdrawing has to be as easy as giving. So the reopen link is
        // not optional when analytics is configured, and it is meaningless when it is not.
        config(['analytics.gtm_container_id' => self::CONTAINER_ID]);

        $this->get('/')->assertOk()->assertSee('Change your choice');
        $this->get('/tr')->assertOk()->assertSee('Seçiminizi değiştir');

        config(['analytics.gtm_container_id' => null]);

        $this->get('/')->assertOk()->assertDontSee('Change your choice');
        $this->get('/tr')->assertOk()->assertDontSee('Seçiminizi değiştir');
    }

    public function test_a_malformed_container_id_fails_closed(): void
    {
        /*
         * The config validates the shape, so a typo withholds the surface instead of
         * injecting a broken tag into every page: `id=GTM` fetches nothing useful, and a
         * value carrying markup would be interpolated into an inline script.
         *
         * The file is re-evaluated with the env value swapped, because the shape check runs
         * when the config array is BUILT and `config()` at runtime bypasses it entirely.
         */
        $original = $_SERVER['GTM_CONTAINER_ID'] ?? null;

        try {
            foreach ([
                'GTM-TEST123' => 'GTM-TEST123',
                'GTM-abc123' => 'GTM-abc123',
                // A typo, a paste with the id missing, an accidental quote, a whole URL.
                'GTM' => null,
                'GTM-' => null,
                "GTM-X'+alert(1)+'" => null,
                'https://www.googletagmanager.com/gtm.js?id=GTM-TEST123' => null,
                '' => null,
            ] as $value => $expected) {
                $_SERVER['GTM_CONTAINER_ID'] = (string) $value;

                $this->assertSame(
                    $expected,
                    (require config_path('analytics.php'))['gtm_container_id'],
                    "GTM_CONTAINER_ID={$value} did not resolve to what the gate promises.",
                );
            }
        } finally {
            if ($original === null) {
                unset($_SERVER['GTM_CONTAINER_ID']);
            } else {
                $_SERVER['GTM_CONTAINER_ID'] = $original;
            }
        }
    }

    public function test_the_marketing_pages_still_set_no_cookie_in_either_state(): void
    {
        /*
         * The consent record lives in `localStorage`, never in a cookie, and this is the
         * assertion that keeps it there. A server-side consent cookie would be the one
         * thing that could falsify the Privacy page's sentence, and it is also the
         * reflexive way to implement this, so it is worth pinning at the header level
         * rather than trusting the review.
         */
        foreach ([null, self::CONTAINER_ID] as $containerId) {
            config(['analytics.gtm_container_id' => $containerId]);

            foreach ($this->pages() as $path) {
                $this->assertSame(
                    [],
                    $this->get($path)->assertOk()->headers->getCookies(),
                    "GET {$path} set a cookie on a page that is published as cookie-free.",
                );
            }
        }
    }

    /**
     * The pages the chrome renders on, in every language.
     *
     * A landing page and a legal page per locale: the gate is in the shared layout, and a
     * page reaching it through another controller is exactly where a withheld surface leaks
     * back in.
     *
     * @return list<string>
     */
    protected function pages(): array
    {
        $default = (string) config('app.default_locale');
        $paths = [];

        foreach (array_values((array) config('magic-starter.supported_locales', [])) as $locale) {
            $prefix = $locale === $default ? '' : '/'.$locale;

            $paths[] = $prefix === '' ? '/' : $prefix;
            $paths[] = $prefix.'/privacy';
        }

        return $paths;
    }
}
