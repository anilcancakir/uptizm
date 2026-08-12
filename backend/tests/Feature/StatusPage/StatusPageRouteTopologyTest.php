<?php

namespace Tests\Feature\StatusPage;

use App\Http\Controllers\StatusPage\ShowStatusPageController;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The shape of the public status-page URL space, asserted at the router rather
 * than through a rendered page.
 *
 * A status page answers on two addressing forms (`<host>/s/{slug}` and
 * `{slug}.<subdomain_host>`), and each of them now carries one URL per language:
 * the default language on the unprefixed URL, every other supported language
 * behind its own prefix. Four properties are pinned here, and every one of them
 * fails SILENTLY rather than loudly:
 *
 *   - The locale constraint. Unconstrained, the subdomain form is a bare
 *     two-letter catch-all in the host bucket, which `RouteCollection` matches
 *     before the unconstrained one, so `{slug}.<host>/up` would be read as a
 *     language and the health check nginx and the deploy script poll would be
 *     answered by the status controller.
 *   - `whereIn([])` compiles to an empty alternation that matches anything, so a
 *     single-language deployment must register no prefixed route at all rather
 *     than one that answers every two-letter segment.
 *   - One URL per language per page: the default language has no prefixed form
 *     and no redirect to one, or hreflang has two canonicals to choose between.
 *   - The route names, which Step-2-and-later code and the sitemap resolve by
 *     name. Asserted with `Route::has()` rather than by reading `route:list`
 *     output, which is formatting rather than routing.
 *
 * The subdomain host comes from `STATUS_PAGE_SUBDOMAIN_HOST` in phpunit.xml
 * (`uptizm.test`): routes are registered at boot, before a test body could set
 * config(), which is also why the single-language case below re-registers the
 * file into a router of its own instead of setting config and hoping.
 */
class StatusPageRouteTopologyTest extends TestCase
{
    protected const HOST = 'uptizm.test';

    protected function setUp(): void
    {
        parent::setUp();

        // Both guards keep the assertions below from passing vacuously: with the
        // host unset the subdomain routes are never registered, and with one
        // supported language the prefixed routes are deliberately absent, so
        // either environment would certify a topology it never exercised.
        $this->assertNotSame(
            '',
            (string) config('status_pages.subdomain_host'),
            'Subdomain addressing is switched off in this environment, so these assertions would prove nothing.',
        );

        $this->assertNotSame(
            [],
            $this->prefixedLocales(),
            'This deployment speaks one language, so the prefixed routes are absent by design.',
        );
    }

    public function test_every_addressing_form_carries_a_route_name(): void
    {
        foreach ([
            'status.show',
            'status.show.localized',
            'status.show.subdomain',
            'status.show.subdomain.localized',
        ] as $name) {
            $this->assertTrue(Route::has($name), "The route named [{$name}] is not registered.");
        }
    }

    public function test_a_supported_language_has_a_url_on_both_addressing_forms(): void
    {
        foreach ($this->prefixedLocales() as $locale) {
            $this->assertSame(
                'status.show.localized',
                $this->matchGet('http://'.self::HOST.'/'.$locale.'/s/acme')->getName(),
            );

            $this->assertSame(
                'status.show.subdomain.localized',
                $this->matchGet('http://acme.'.self::HOST.'/'.$locale)->getName(),
            );
        }
    }

    public function test_the_prefixed_path_carries_the_language_before_the_slug(): void
    {
        /*
         * Not decoration. Route parameters reach the action in URI ORDER, so on
         * `/tr/s/acme` a positional `__invoke(Request $request, string $slug)`
         * receives `tr` as the slug and answers 404 for a page that exists. The
         * fix is to read the slug off the route by name, as
         * `Marketing\ShowServiceStatusController::__invoke()` already does for
         * `/{locale}/status/{slug}`; this assertion is what names the constraint
         * the controller has to satisfy.
         */
        $route = $this->matchGet('http://'.self::HOST.'/'.$this->prefixedLocales()[0].'/s/acme');

        $this->assertSame(['locale', 'slug'], $route->parameterNames());
    }

    public function test_the_default_language_has_exactly_one_url(): void
    {
        // Not a redirect from one to the other either: `/en/s/acme` must not exist
        // at all, or the page competes with itself for the same query and hreflang
        // has two canonicals to name for one language.
        $default = (string) config('app.default_locale');

        $this->get('http://'.self::HOST.'/'.$default.'/s/acme')->assertNotFound();

        /*
         * The subdomain form is asserted at the router rather than by status code,
         * because something else already answers there: `routes/marketing.php`
         * registers `Route::redirect('/en', '/', 301)` with no domain constraint,
         * so it answers on EVERY host and on a status subdomain it lands the
         * visitor on that subdomain's own default-language page. Harmless, and not
         * this file's to change. What has to hold here is that no STATUS route
         * claims the default language's prefix.
         */
        $onTheSubdomain = $this->matchGet('http://acme.'.self::HOST.'/'.$default);

        $this->assertNotSame('status.show.subdomain', $onTheSubdomain->getName());
        $this->assertNotSame('status.show.subdomain.localized', $onTheSubdomain->getName());
    }

    public function test_a_language_we_do_not_speak_is_not_a_page(): void
    {
        // A 404 rather than the default language under a 200: otherwise one
        // document is published at unbounded addresses for a crawler to index.
        foreach (['de', 'fr', 'xx'] as $locale) {
            $this->get('http://'.self::HOST.'/'.$locale.'/s/acme')->assertNotFound();
            $this->get('http://acme.'.self::HOST.'/'.$locale)->assertNotFound();
        }
    }

    public function test_the_health_check_is_not_mistaken_for_a_language(): void
    {
        $this->get('http://'.self::HOST.'/up')
            ->assertOk()
            ->assertSee('Application up');

        // The subdomain is where this can actually break: the host-constrained
        // bucket is matched first, so an unconstrained `/{locale}` there would
        // swallow `/up` on every status subdomain while the apex kept answering.
        foreach ([self::HOST, 'acme.'.self::HOST] as $host) {
            $this->assertStringNotContainsString(
                ShowStatusPageController::class,
                $this->matchGet('http://'.$host.'/up')->getActionName(),
                'The health check on '.$host.' is being answered by the status page controller.',
            );
        }
    }

    public function test_a_single_language_deployment_registers_no_prefixed_route(): void
    {
        /*
         * `whereIn([])` compiles to an empty alternation, which matches every
         * segment rather than none, so the prefixed routes must not be registered
         * at all when there is no other language. Asserted by loading the route
         * file into a router of its own, because the real collection was built at
         * boot and cannot see a config() written in a test body.
         *
         * The two-language case is asserted through the SAME helper, so a helper
         * that silently returned nothing would fail here rather than certify the
         * absence it was asked about.
         */
        $bothLanguages = $this->routeNamesRegisteredFor(['en', 'tr']);

        $this->assertContains('status.show.localized', $bothLanguages);
        $this->assertContains('status.show.subdomain.localized', $bothLanguages);

        $defaultOnly = $this->routeNamesRegisteredFor(['en']);

        $this->assertContains('status.show', $defaultOnly);
        $this->assertContains('status.show.subdomain', $defaultOnly);
        $this->assertNotContains('status.show.localized', $defaultOnly);
        $this->assertNotContains('status.show.subdomain.localized', $defaultOnly);
    }

    /**
     * The languages that get a prefix: everything the product speaks except the
     * one served on the unprefixed URL.
     *
     * Reads `app.default_locale` and never `app.locale`, which a rendered request
     * rewrites inside this same process.
     *
     * @return list<string>
     */
    protected function prefixedLocales(): array
    {
        return array_values(array_diff(
            (array) config('magic-starter.supported_locales', []),
            [(string) config('app.default_locale')],
        ));
    }

    /**
     * The route the live collection matches for a GET of the given URL.
     */
    protected function matchGet(string $url): RoutingRoute
    {
        return Route::getRoutes()->match(Request::create($url));
    }

    /**
     * Re-register `routes/status.php` against a throwaway router under a given
     * supported-locale list, and return the names it produced.
     *
     * The facade is swapped rather than the collection mutated: the route file
     * registers through `Route::`, and the live collection has to survive this
     * test intact for the ones after it.
     *
     * @param  list<string>  $supportedLocales
     * @return list<string>
     */
    protected function routeNamesRegisteredFor(array $supportedLocales): array
    {
        $live = Route::getFacadeRoot();
        $probe = new Router(app('events'), app());

        config(['magic-starter.supported_locales' => $supportedLocales]);
        Route::swap($probe);

        try {
            require base_path('routes/status.php');
        } finally {
            Route::swap($live);
        }

        $names = [];

        foreach ($probe->getRoutes()->getRoutes() as $route) {
            if (is_string($route->getName())) {
                $names[] = $route->getName();
            }
        }

        return $names;
    }
}
