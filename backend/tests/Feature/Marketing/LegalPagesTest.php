<?php

namespace Tests\Feature\Marketing;

use App\Http\Middleware\SetMarketingLocale;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The four long-form pages: Privacy, Terms, Contact, FAQ.
 *
 * Each one is a document rendered through the shared marketing shell, on the apex for
 * the default language and under a path prefix for every other. What this file pins is
 * the ROUTING and the per-page head contract rather than any wording, because the
 * wording arrives in later steps while the contract has to hold from the first commit:
 *
 *   - one URL per language per page, with the default language's prefixed form a 301 so
 *     `/privacy` and `/en/privacy` are not two English documents competing for one query;
 *   - a canonical that names the page ITSELF. The head used to compose its canonical from
 *     the landing route, and a second page rendering through it would have told every
 *     crawler the two documents were the same one;
 *   - a language we do not speak is a 404 and not a blank page in English;
 *   - and the routes carry no session, no cookie and no CSRF middleware, because the
 *     Privacy page these routes serve states that these pages store nothing on the
 *     visitor's device.
 *
 * The page list and the locale list are both read from the source the routes are
 * registered from, so adding either extends this file instead of leaving the new URL
 * unasserted.
 */
class LegalPagesTest extends TestCase
{
    /**
     * The document pages, matching both `resources/legal/<page>.<locale>.md` and the
     * route paths. Typed here rather than derived because the routes themselves are a
     * typed list: there is no config or enum behind them to read.
     */
    protected const PAGES = [
        'privacy',
        'terms',
        'contact',
        'faq',
    ];

    /**
     * Middleware that reads or writes a session, ships a cookie, or gates on a CSRF
     * token. Matched as a SUBSTRING against the resolved class name, exactly as
     * `CookieTest` does it: on Laravel 13 the CSRF middleware is `PreventRequestForgery`
     * and `VerifyCsrfToken` is an empty compat subclass, so either name may legitimately
     * appear and both must be caught, and a substring also catches a project subclass.
     */
    protected const SESSION_COUPLED = [
        'StartSession',
        'EncryptCookies',
        'AddQueuedCookiesToResponse',
        'ShareErrorsFromSession',
        'PreventRequestForgery',
        'VerifyCsrfToken',
    ];

    public function test_every_document_is_served_in_every_supported_language(): void
    {
        foreach (self::PAGES as $page) {
            foreach ($this->supported() as $locale) {
                $this->get($this->pathFor($locale, $page))
                    ->assertOk()
                    ->assertSee('<html lang="'.$locale.'"', escape: false);
            }
        }
    }

    public function test_the_default_language_has_exactly_one_url_per_document(): void
    {
        /*
         * `assertRedirect` here and nowhere else in this plan: it is banned for the
         * contact form, where `TestResponse::session()` starts a store on demand and makes
         * a session-flavoured assertion pass against a sessionless route. This is a plain
         * `Route::redirect`, built by the framework's RedirectController without touching a
         * store at all, so the status and the Location header are the whole of the fact.
         */
        foreach (self::PAGES as $page) {
            $this->get('/'.config('app.default_locale').'/'.$page)
                ->assertStatus(301)
                ->assertRedirect('/'.$page);
        }
    }

    public function test_a_language_we_do_not_speak_is_not_a_document(): void
    {
        foreach (self::PAGES as $page) {
            foreach (['de', 'fr', 'es'] as $locale) {
                $this->get('/'.$locale.'/'.$page)->assertNotFound();
            }
        }
    }

    public function test_the_health_check_is_not_mistaken_for_a_language(): void
    {
        /*
         * `/up` is two letters and so is the locale segment these routes add. The
         * `whereIn` constraint listing the real locales is the only thing keeping the
         * health endpoint nginx and the deploy script poll reachable, and this step
         * multiplied the number of routes that could swallow it by four.
         */
        $this->get('/up')->assertOk();
    }

    public function test_each_document_is_canonical_for_itself(): void
    {
        foreach (self::PAGES as $page) {
            foreach ($this->supported() as $locale) {
                $this->get($this->pathFor($locale, $page))
                    ->assertSee(
                        'rel="canonical" href="'.url($this->pathFor($locale, $page)).'"',
                        escape: false,
                    );
            }
        }
    }

    public function test_each_document_declares_every_language_as_an_alternate_of_every_other(): void
    {
        foreach (self::PAGES as $page) {
            foreach ($this->supported() as $pageLocale) {
                $response = $this->get($this->pathFor($pageLocale, $page));

                foreach ($this->supported() as $alternate) {
                    $response->assertSee(
                        'hreflang="'.$alternate.'" href="'.url($this->pathFor($alternate, $page)).'"',
                        escape: false,
                    );
                }

                // The fallback for a visitor whose language we do not speak, and it points
                // at THIS document in the default language rather than at the site root.
                $response->assertSee(
                    'hreflang="x-default" href="'.url($this->pathFor(config('app.default_locale'), $page)).'"',
                    escape: false,
                );
            }
        }
    }

    public function test_each_document_describes_itself_and_not_the_home_page(): void
    {
        /*
         * The meta description is what a crawler and a link preview show, so four pages
         * sharing the landing page's sentence describe the wrong document four times. The
         * sentences themselves are not asserted (they are copy, and copy moves): what is
         * asserted is that each one is present, non-empty, distinct from the others, and
         * not the one the home page uses.
         */
        $landing = $this->descriptionOf($this->get('/'));

        $this->assertNotSame('', $landing, 'The landing page emitted no description, so this comparison checks nothing.');

        $seen = [];

        foreach (self::PAGES as $page) {
            $description = $this->descriptionOf($this->get('/'.$page));

            $this->assertNotSame('', $description, "/{$page} emitted no meta description.");
            $this->assertNotSame($landing, $description, "/{$page} describes itself with the home page's sentence.");
            $this->assertNotContains($description, $seen, "/{$page} reuses another document's description.");

            $seen[] = $description;
        }
    }

    public function test_each_document_is_titled_in_the_language_being_read(): void
    {
        /*
         * A missing key in lang/tr.json falls back to the English source string in
         * silence: no exception, no log line, just an English title on a Turkish page.
         * These four titles and the footer labels beside them are the strings this step
         * adds, so they are named here in both languages rather than checked structurally.
         */
        $this->get('/privacy')->assertSee('<title>Privacy Policy | ', escape: false);
        $this->get('/terms')->assertSee('<title>Terms of Service | ', escape: false);
        $this->get('/faq')->assertSee('<title>Frequently Asked Questions | ', escape: false);

        $this->get('/tr/privacy')
            ->assertSee('<title>Gizlilik Politikası | ', escape: false)
            ->assertDontSee('Privacy Policy');

        $this->get('/tr/terms')
            ->assertSee('<title>Kullanım Koşulları | ', escape: false)
            ->assertDontSee('Terms of Service');

        $this->get('/tr/faq')
            ->assertSee('<title>Sıkça Sorulan Sorular | ', escape: false)
            ->assertDontSee('Frequently Asked Questions');
    }

    public function test_the_footer_links_every_document_in_the_language_being_read(): void
    {
        /*
         * The footer linked no Privacy and no Terms until this step, and `ChromeTest`
         * asserted that absence on purpose: a 404 behind "Privacy" is worse than no link
         * at all. Now that the pages exist the link has to exist with them, and it has to
         * keep a Turkish reader in Turkish rather than dropping them onto the English
         * document.
         */
        foreach ($this->supported() as $locale) {
            $response = $this->get($locale === config('app.default_locale') ? '/' : '/'.$locale);

            foreach (self::PAGES as $page) {
                $response->assertSee('href="'.$this->pathFor($locale, $page).'"', escape: false);
            }
        }
    }

    public function test_no_unreplaced_placeholder_reaches_a_published_document(): void
    {
        /*
         * `LegalDocument` leaves an unmapped `[[key]]` in the output verbatim rather than
         * stripping it, deliberately: a visible placeholder is a bug report and a silently
         * blank legal sentence is not. So a bracket surviving to the page means a
         * controller forgot a replacement, and the Contact page is the live case, since it
         * interpolates the operator's contact address.
         */
        foreach (self::PAGES as $page) {
            foreach ($this->supported() as $locale) {
                $this->get($this->pathFor($locale, $page))
                    ->assertDontSee('[[')
                    ->assertDontSee(']]');
            }
        }
    }

    public function test_every_table_of_contents_link_lands_on_a_heading_that_exists(): void
    {
        /*
         * The table of contents is generated from the document's own headings, so a link
         * in it that resolves to nothing means the slug the TOC printed and the id the
         * heading carries have come apart. `LegalDocument` guards against that by parsing
         * the Markdown a second time rather than scraping the rendered HTML, and this is
         * what proves the guarantee end to end on a real response.
         *
         * The fragment pattern matches ANYTHING between the quotes on purpose. A Turkish
         * heading slugs to `haklarınız`, and the `[a-zA-Z0-9_-]+` class this repo used
         * elsewhere silently skipped every one of those, which made the same walk on the
         * landing page pass while checking nothing at all on the pages that carry
         * non-ASCII headings. The count assertion below is the other half of that lesson:
         * a walk over an empty match list is not a passing test.
         */
        foreach (self::PAGES as $page) {
            foreach ($this->supported() as $locale) {
                $path = $this->pathFor($locale, $page);
                $html = $this->get($path)->getContent();

                preg_match_all('/href="#([^"]+)"/u', $html, $matches);

                $anchors = array_values(array_diff(array_unique($matches[1]), ['content']));

                $this->assertNotSame(
                    [],
                    $anchors,
                    "GET {$path} printed no table-of-contents link, so this walk checked nothing.",
                );

                foreach ($anchors as $anchor) {
                    $this->assertStringContainsString(
                        'id="'.$anchor.'"',
                        $html,
                        "GET {$path} links #{$anchor} but no element on the page carries that id.",
                    );
                }
            }
        }
    }

    public function test_the_contact_page_reaches_the_operator_from_config(): void
    {
        // The whole point of the bracketed placeholder: the address is interpolated from
        // `config/legal.php` at render time, so a change there moves the page instead of
        // starting a hunt through eight Markdown files.
        config(['legal.contact_email' => 'someone@example.test']);

        foreach ($this->supported() as $locale) {
            $this->get($this->pathFor($locale, 'contact'))
                ->assertOk()
                ->assertSee('someone@example.test');
        }
    }

    public function test_no_document_route_carries_session_or_csrf_middleware(): void
    {
        /*
         * Asserted the same way `CookieTest` does it, and asserted here as well rather
         * than left to ordering, because it is NOT implied by Step 4 having moved the
         * landing page: `routes/web.php` is loaded as `Route::middleware('web')->group()`,
         * so a route registered in that file re-inherits `StartSession` whatever else was
         * done to its neighbours. These four pages must live in `routes/marketing.php`.
         * The failure is otherwise silent until somebody curls for a `Set-Cookie` header.
         */
        foreach ($this->documentPaths() as $path) {
            foreach ($this->middlewareFor($path) as $entry) {
                foreach (self::SESSION_COUPLED as $coupled) {
                    $this->assertStringNotContainsString(
                        $coupled,
                        $entry,
                        "GET {$path} resolves {$entry}, which puts a cookie on a page that needs none. "
                        .'These routes belong in routes/marketing.php, outside the `web` group.',
                    );
                }
            }
        }
    }

    public function test_no_document_route_sits_in_the_web_group(): void
    {
        // The group NAME, before resolution: the test above would also pass against a
        // hand-listed copy of `web` minus its session pieces, while this one keeps the
        // routes on the lean group they were registered under.
        foreach ($this->documentPaths() as $path) {
            $this->assertNotContains(
                'web',
                $this->routeFor($path)->gatherMiddleware(),
                "GET {$path} is in the `web` group, so it inherits StartSession no matter what else is done to it.",
            );
        }
    }

    public function test_the_document_routes_still_bind_parameters_and_set_the_locale(): void
    {
        // The positive control for the two tests above: both pass just as happily against
        // a route that lost its middleware entirely, or one that stopped existing in the
        // shape they look for, so without this a broken registration reads as green.
        foreach ($this->documentPaths() as $path) {
            $middleware = $this->middlewareFor($path);

            $this->assertContains(SubstituteBindings::class, $middleware, "GET {$path} no longer binds route parameters.");
            $this->assertContains(SetMarketingLocale::class, $middleware, "GET {$path} no longer applies the language its URL asks for.");
        }
    }

    /**
     * The languages the whole product speaks, from the same config the routes read.
     *
     * @return list<string>
     */
    protected function supported(): array
    {
        return array_values((array) config('magic-starter.supported_locales', []));
    }

    /**
     * The path one document is served on in one language. The default language lives on
     * the apex, so it takes no prefix.
     *
     * Reads `app.default_locale` and never `app.locale`: the request under test calls
     * `App::setLocale()`, which rewrites the latter inside this very process, so a helper
     * reading it afterwards computes the wrong path for every language.
     */
    protected function pathFor(string $locale, string $page): string
    {
        return $locale === config('app.default_locale')
            ? '/'.$page
            : '/'.$locale.'/'.$page;
    }

    /**
     * Every path this step registers, the default locale's 301 forms included: a redirect
     * response carries headers like any other, and that route is the easy one to forget.
     *
     * @return list<string>
     */
    protected function documentPaths(): array
    {
        $paths = [];

        foreach (self::PAGES as $page) {
            $paths[] = '/'.$page;
            $paths[] = '/'.config('app.default_locale').'/'.$page;

            foreach (array_diff($this->supported(), [config('app.default_locale')]) as $locale) {
                $paths[] = '/'.$locale.'/'.$page;
            }
        }

        return $paths;
    }

    /**
     * The `meta name="description"` content of a response, or an empty string.
     */
    protected function descriptionOf(TestResponse $response): string
    {
        preg_match('/<meta name="description" content="([^"]*)">/', $response->getContent(), $matches);

        return $matches[1] ?? '';
    }

    /**
     * The resolved middleware class list for the route that answers a path.
     *
     * @return list<string>
     */
    protected function middlewareFor(string $path): array
    {
        $middleware = app('router')->gatherRouteMiddleware($this->routeFor($path));

        foreach ($middleware as $entry) {
            // A closure middleware would sail through every substring assertion above
            // without being readable at all, so fail on it here instead.
            $this->assertIsString($entry, "GET {$path} carries a middleware that is not a class name.");
        }

        return array_values($middleware);
    }

    /**
     * The route that actually answers a GET of a path.
     *
     * Matched from a request rather than looked up by name: the default locale's 301 has
     * no name, and matching is what a visitor's request does, so a route that exists but
     * is shadowed by another cannot pass.
     */
    protected function routeFor(string $path): Route
    {
        return app('router')->getRoutes()->match(Request::create($path, 'GET'));
    }
}
