<?php

namespace Tests\Feature\Admin;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The Filament staff panel lives on its own host and touches nothing else.
 *
 * Installing a Livewire panel adds `StartSession`, `EncryptCookies` and
 * `PreventRequestForgery` to this application for the first time. Two public
 * surfaces are registered OUTSIDE the `web` group precisely so they carry none of
 * that: the read-only marketing pages (`routes/marketing.php`) and the public
 * status pages (`routes/status.php`). `resources/legal/privacy.en.md:208-212`
 * publishes the cookie-free property of those pages as an unqualified claim, so a
 * leak here is a falsified legal statement rather than a tidiness problem. That
 * property is what the first test below pins, and it is the one thing the panel's
 * own documentation could not tell us: it describes what the panel mounts, not
 * what it leaves alone.
 *
 * The second thing pinned here is a production-only routing collision. The panel
 * is mounted with `->path('')` so it owns the ROOT of `config('uptizm.admin_host')`.
 * `routes/status.php:54` registers `GET /` under `{slug}.<subdomain_host>` whenever
 * `status_pages.subdomain_host` is set, a host pattern that matches the label
 * `admin` as happily as any customer's slug, and a host-constrained route is
 * matched ahead of the unconstrained landing route. `subdomain_host` is EMPTY in
 * `.env.example` and set in production, so an unowned `admin.<host>/` would 404
 * through `RejectReservedStatusPageSlug` (`admin` is a reserved slug) in
 * production while working on every developer's machine. That is why this asserts
 * against a route collection compiled WITH the subdomain host configured instead
 * of reasoning about Laravel's matching order.
 *
 * TECHNIQUE, AND WHAT IS DELIBERATELY NOT ASSERTED
 *
 * Resolved middleware BY NAME, via `Router::gatherRouteMiddleware()`, which
 * expands group names into classes so a route that re-inherited `web` shows
 * `StartSession` in the list. Borrowed wholesale from
 * `tests/Feature/Marketing/CookieTest.php`, which explains at length why the
 * response header is not the thing to look at: PHPUnit cannot prove the absence of
 * a `Set-Cookie`, and `TestResponse::session()` happily hands back a store no
 * middleware ever created, so an assertion phrased around the session proves
 * nothing. Never call `TestResponse::session()` on these routes.
 */
class PanelIsolationTest extends TestCase
{
    /**
     * The host the status-page wildcard route is registered under, supplied by
     * `phpunit.xml` because routes are registered at boot, before a test body
     * could reach `config()`.
     */
    protected const SUBDOMAIN_HOST = 'uptizm.test';

    /**
     * The environment key `config/uptizm.php` reads the panel host from.
     */
    protected const ADMIN_HOST_ENV = 'ADMIN_HOST';

    /**
     * Middleware that reads or writes a session, ships a cookie, or gates on a
     * CSRF token, matched as a SUBSTRING against the resolved class name.
     *
     * Copied from `CookieTest::SESSION_COUPLED` rather than shared: that constant
     * is `protected`, and widening another suite's API to save six strings is a
     * worse trade than restating them here. Keep the two lists in step. On
     * Laravel 13 the CSRF middleware is `PreventRequestForgery` and
     * `VerifyCsrfToken` is an empty compatibility subclass, so both names must be
     * caught, and a substring match also catches a project subclass an exact
     * class comparison would miss.
     */
    protected const SESSION_COUPLED = [
        'StartSession',
        'EncryptCookies',
        'AddQueuedCookiesToResponse',
        'ShareErrorsFromSession',
        'PreventRequestForgery',
        'VerifyCsrfToken',
    ];

    /**
     * Put the panel on a label UNDER the status-page subdomain host before the
     * application boots.
     *
     * Without this the panel defaults to `admin.<APP_URL host>`, which shares no
     * parent domain with `phpunit.xml`'s `uptizm.test`, the wildcard route could
     * never have matched it, and the collision test below would pass while
     * proving nothing at all. `$_SERVER` is the seam because Laravel's env
     * repository reads it live and loads `.env` immutably, so a value set here is
     * not overwritten; `config()` is not, because the route collection is built
     * during boot.
     */
    protected function setUp(): void
    {
        $_SERVER[self::ADMIN_HOST_ENV] = 'admin.'.self::SUBDOMAIN_HOST;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_SERVER[self::ADMIN_HOST_ENV]);

        parent::tearDown();
    }

    public function test_the_cookie_free_public_surfaces_carry_no_session_middleware_after_the_panel_install(): void
    {
        foreach ($this->publicSurfaces() as $name => $url) {
            $middleware = $this->middlewareFor($url);

            foreach ($middleware as $entry) {
                foreach (self::SESSION_COUPLED as $coupled) {
                    $this->assertStringNotContainsString(
                        $coupled,
                        $entry,
                        "The `{$name}` route ({$url}) resolves {$entry}. The Filament panel's session "
                        .'middleware belongs to the panel host alone; the marketing and status surfaces '
                        .'publish that they set no cookie.',
                    );
                }
            }
        }
    }

    public function test_the_cookie_free_public_surfaces_still_answer_and_still_bind_parameters(): void
    {
        /*
         * The positive control. The test above passes just as happily against a
         * route that lost its middleware entirely, or against a URL that stopped
         * resolving to the route this suite thinks it does, so without this a
         * broken registration reads as a green suite.
         */
        foreach ($this->publicSurfaces() as $name => $url) {
            $route = $this->routeFor($url);

            $this->assertSame(
                $name,
                $route->getName(),
                "{$url} no longer resolves to the `{$name}` route.",
            );

            $this->assertContains(
                SubstituteBindings::class,
                $this->middlewareFor($url),
                "The `{$name}` route ({$url}) no longer binds route parameters.",
            );
        }
    }

    public function test_the_panel_does_carry_the_session_middleware_the_public_surfaces_must_not(): void
    {
        /*
         * The mirror control, and the one that gives the substring list its
         * meaning: if every name in SESSION_COUPLED were misspelled, the first
         * test would still pass. A Livewire panel cannot work without a session,
         * so the panel's own login route is where those names must appear.
         */
        $middleware = $this->middlewareFor($this->panelUrl('/login'));

        foreach (['StartSession', 'EncryptCookies', 'PreventRequestForgery'] as $expected) {
            // `array_any()` would read better and is PHP 8.4; composer.json pins
            // `php: ^8.3`, so this stays a filter.
            $matches = array_filter(
                $middleware,
                fn (string $entry): bool => str_contains($entry, $expected),
            );

            $this->assertNotSame(
                [],
                $matches,
                "The panel's login route resolves no {$expected}, so this suite's substring list is "
                .'no longer able to detect a session leak onto the public surfaces.',
            );
        }
    }

    public function test_the_panel_owns_the_root_of_its_own_host(): void
    {
        $this->assertCollisionIsActuallyPossible();

        $route = $this->routeFor($this->panelUrl('/'));

        $this->assertStringStartsWith(
            'filament.admin.',
            (string) $route->getName(),
            'The root of the panel host resolved to `'.$route->getName().'` instead of a panel route. '
            .'With `->path(\'\')` the panel must own that root; the likely thief is the wildcard '
            .'status-page route, whose `{slug}` pattern matches the label `admin` and then 404s it '
            .'through RejectReservedStatusPageSlug.',
        );
    }

    public function test_the_compiled_route_collection_also_keeps_the_panel_on_that_root(): void
    {
        /*
         * The gap the test above has on its own: it runs against an UNCACHED
         * collection and production runs a cached one, because `artisan optimize`
         * is part of every deploy. Those are two different matchers and a suite
         * that only exercises the first cannot speak for the second. Compiled in
         * memory rather than through `route:cache`, so the run leaves no artefact
         * behind for the next test or the next developer.
         */
        $this->assertCollisionIsActuallyPossible();

        $route = $this->compiledRouteFor($this->panelUrl('/'));

        $this->assertStringStartsWith(
            'filament.admin.',
            (string) $route->getName(),
            'The cached matcher hands the panel host\'s root to `'.$route->getName().'`. '
            .'The uncached matcher may still be resolving it correctly, which is exactly how this '
            .'would reach production unnoticed.',
        );
    }

    public function test_the_panel_login_and_dashboard_answer_where_the_provider_documents_them(): void
    {
        /*
         * `AdminPanelProvider`'s docblock states these two URLs, and the panel
         * access-gate QA plus the Octane memory measurement both drive them. A
         * measurement against the marketing landing page would be worthless, so
         * the addresses are asserted rather than trusted.
         *
         * The dashboard sitting on `/dashboard` rather than on the panel root is
         * `App\Filament\Pages\Dashboard::$routePath` doing its job: it frees the
         * root for Filament's own `home` route, which is what makes
         * `filament.admin.home` exist and `Panel::getUrl()` resolve to this host
         * instead of falling back to APP_URL.
         */
        $login = $this->routeFor($this->panelUrl('/login'));
        $dashboard = $this->routeFor($this->panelUrl('/dashboard'));

        $this->assertSame('filament.admin.auth.login', $login->getName());
        $this->assertSame('filament.admin.pages.dashboard', $dashboard->getName());

        $this->assertSame(
            'admin.'.self::SUBDOMAIN_HOST,
            $dashboard->getDomain(),
            'The dashboard is not host-constrained, so it answers on the apex too.',
        );

        /*
         * The concrete harm behind Filament issue #19549, verified against this
         * install by moving `$routePath` back to `/`: with the dashboard sitting
         * on the panel root, `filament.admin.home` is never registered and
         * `Panel::getUrl()` falls back to `url($panel->getPath())`, which returned
         * `http://localhost:8000` (the MARKETING APEX). Every "back to the panel"
         * link and the post-login redirect would leave the panel host entirely.
         */
        $this->assertStringContainsString(
            'admin.'.self::SUBDOMAIN_HOST,
            (string) Filament::getPanel('admin')->getUrl(),
            'The panel advertises a URL outside its own host, which means `filament.admin.home` was '
            .'never registered. Check that App\Filament\Pages\Dashboard still moves $routePath off the '
            .'panel root.',
        );
    }

    public function test_the_wildcard_status_page_route_still_owns_a_customer_label(): void
    {
        /*
         * The other half of the collision. Pinning that the panel wins on its own
         * host says nothing about whether it stole every OTHER label under the
         * domain, which would take every customer's subdomain-addressed status
         * page offline. The apex is checked in the same breath, because the
         * landing route carries no host constraint and is the third claimant on
         * `GET /`.
         */
        $this->assertCollisionIsActuallyPossible();

        $this->assertSame(
            'status.show.subdomain',
            $this->routeFor('http://acme.'.self::SUBDOMAIN_HOST.'/')->getName(),
        );

        $this->assertSame(
            'landing',
            $this->routeFor('http://'.self::SUBDOMAIN_HOST.'/')->getName(),
        );
    }

    public function test_the_panel_is_not_reachable_on_the_apex(): void
    {
        // `->path('')` on a host-constrained panel must not leave a second,
        // unconstrained copy of the login page on the marketing host: that host
        // publishes that it sets no cookie, and a login page cannot honour it.
        $this->assertNull(
            $this->matchOrNull('http://'.self::SUBDOMAIN_HOST.'/login'),
            'The panel login answers on the apex host as well as on the panel host.',
        );
    }

    public function test_the_panel_has_no_tenancy(): void
    {
        // Tenancy scopes every query to one tenant, which is the exact opposite of
        // a cross-team staff console. This is the executable form of the
        // `grep -r "->tenant("` guardrail.
        $this->assertFalse(Filament::getPanel('admin')->hasTenancy());
    }

    public function test_every_product_config_key_this_plan_reads_resolves(): void
    {
        /*
         * `config/uptizm.php` is written once and read by four separate
         * subsystems, so a missing key surfaces as a null at runtime in whichever
         * of them runs first rather than as a boot failure here.
         */
        $this->assertIsString(config('uptizm.admin_host'));
        $this->assertNotSame('', config('uptizm.admin_host'));
        $this->assertIsArray(config('uptizm.staff_emails'));
        $this->assertIsString(config('uptizm.system_team_name'));
        $this->assertIsString(config('uptizm.system_team_email'));
        $this->assertIsString(config('uptizm.bot_user_agent'));

        // The bot must introduce itself with a contact URL: that is what lets an
        // operator on the other side reach us instead of blocking us.
        $this->assertStringContainsString('http', (string) config('uptizm.bot_user_agent'));
    }

    /**
     * Fail loudly when this environment cannot express the collision at all.
     *
     * Both halves have to hold in the SAME route collection: the wildcard status
     * route must be registered, and the panel host must be a label under the host
     * it wildcards. Either missing turns every collision assertion in this file
     * into a tautology, which is precisely the shape of the original report this
     * suite exists to settle.
     */
    protected function assertCollisionIsActuallyPossible(): void
    {
        $this->assertSame(
            self::SUBDOMAIN_HOST,
            config('status_pages.subdomain_host'),
            'Subdomain addressing is switched off here, so the wildcard route is not registered and '
            .'these assertions would prove nothing. phpunit.xml sets STATUS_PAGE_SUBDOMAIN_HOST.',
        );

        $this->assertSame(
            'admin.'.self::SUBDOMAIN_HOST,
            config('uptizm.admin_host'),
            'The panel host is not a label under the status-page subdomain host, so the wildcard route '
            .'could never have matched it. Cached config is the usual cause: run '
            .'`php artisan config:clear` (which `composer test` does for you).',
        );
    }

    /**
     * The public surfaces whose cookie-free property this file protects, keyed by
     * route name.
     *
     * `privacy` is in the list because it is the page that PUBLISHES the claim,
     * and `status.show` because it is the other surface on the lean `static`
     * group.
     *
     * @return array<string, string>
     */
    protected function publicSurfaces(): array
    {
        $host = 'http://'.self::SUBDOMAIN_HOST;

        return [
            'landing' => $host.'/',
            'privacy' => $host.'/privacy',
            'status.show' => $host.'/s/acme',
        ];
    }

    /**
     * A URL on the panel's own host.
     */
    protected function panelUrl(string $path): string
    {
        return 'http://admin.'.self::SUBDOMAIN_HOST.$path;
    }

    /**
     * The resolved middleware class list for the route that answers a URL.
     *
     * @return list<string>
     */
    protected function middlewareFor(string $url): array
    {
        $middleware = app('router')->gatherRouteMiddleware($this->routeFor($url));

        foreach ($middleware as $entry) {
            // A closure middleware would sail through every substring assertion in
            // this file without being readable at all, so fail on it here instead.
            $this->assertIsString($entry, "{$url} carries a middleware that is not a class name.");
        }

        return array_values($middleware);
    }

    /**
     * The route that actually answers a GET of the given URL.
     *
     * Resolved by matching a request rather than by route name, because matching
     * is what a visitor's request does: a route that exists but is shadowed by
     * another cannot pass.
     */
    protected function routeFor(string $url): Route
    {
        return app('router')->getRoutes()->match(Request::create($url, 'GET'));
    }

    /**
     * The same, against the collection production actually runs.
     */
    protected function compiledRouteFor(string $url): Route
    {
        return app('router')->getRoutes()
            ->toCompiledRouteCollection(app('router'), app())
            ->match(Request::create($url, 'GET'));
    }

    /**
     * The matched route name, or null when nothing answers the URL.
     */
    protected function matchOrNull(string $url): ?string
    {
        try {
            return $this->routeFor($url)->getName();
        } catch (HttpException) {
            return null;
        }
    }
}
