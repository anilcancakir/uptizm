<?php

namespace Tests\Feature\Marketing;

use App\Http\Middleware\SetMarketingLocale;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * The read-only marketing pages store nothing on the visitor's device.
 *
 * This is a legal contract, not housekeeping. ePrivacy Art. 5(3) exempts storage
 * that is strictly necessary to the service the user asked for, and every
 * regulator assesses that necessity per PURPOSE from the user's point of view
 * ("helpful" does not qualify). A page with no form asks for no session-dependent
 * service, so a session cookie set there is not exempt and would drag a consent
 * question onto a page that has nothing to consent to. The Privacy page states
 * plainly that these pages set no cookie; this test is what keeps that sentence
 * true. See routes/marketing.php for the full reasoning.
 *
 * What is asserted here, and what is deliberately NOT: PHPUnit cannot prove the
 * absence of a `Set-Cookie` header. `TestResponse::session()` resolves
 * `app('session.store')` and starts it on demand, and the framework's own test
 * plumbing is happy to hand back a store that no middleware ever created, so an
 * assertion phrased around the session proves nothing at all. What IS provable
 * here is the routes' RESOLVED middleware list: `Router::gatherRouteMiddleware()`
 * expands middleware GROUP names into classes, so a route that re-inherited the
 * `web` group shows `StartSession` in that list and fails below. The header itself
 * is proved by curl against a running server (plan Step 10).
 */
class CookieTest extends TestCase
{
    /**
     * Middleware that reads or writes a session, ships a cookie, or gates on a
     * CSRF token. Matched as a SUBSTRING against the resolved class name rather
     * than compared exactly: on Laravel 13 the CSRF middleware is
     * `PreventRequestForgery` and `VerifyCsrfToken` is an empty compat subclass,
     * so either name may legitimately appear and both must be caught. A substring
     * also catches a project subclass, which an exact class match would miss.
     */
    protected const SESSION_COUPLED = [
        'StartSession',
        'EncryptCookies',
        'AddQueuedCookiesToResponse',
        'ShareErrorsFromSession',
        'PreventRequestForgery',
        'VerifyCsrfToken',
    ];

    public function test_no_read_only_marketing_route_carries_session_or_csrf_middleware(): void
    {
        foreach ($this->marketingPaths() as $path) {
            $middleware = $this->middlewareFor($path);

            foreach ($middleware as $entry) {
                foreach (self::SESSION_COUPLED as $coupled) {
                    $this->assertStringNotContainsString(
                        $coupled,
                        $entry,
                        "GET {$path} resolves {$entry}, which puts a cookie on a page that needs none. "
                        .'Marketing routes belong in routes/marketing.php, outside the `web` group.',
                    );
                }
            }
        }
    }

    public function test_no_marketing_route_sits_in_the_web_group(): void
    {
        /*
         * The group NAME, before resolution. This is the same fact
         * `php artisan route:list --path=/` prints, and it is worth pinning
         * separately: the test above would also pass if somebody replaced `web`
         * with a hand-listed copy of its contents minus the session pieces, while
         * this one keeps the routes on the lean group they were moved to.
         */
        foreach ($this->marketingPaths() as $path) {
            $this->assertNotContains(
                'web',
                $this->routeFor($path)->gatherMiddleware(),
                "GET {$path} is in the `web` group, so it inherits StartSession no matter what else is done to it.",
            );
        }
    }

    public function test_the_marketing_routes_still_bind_parameters_and_set_the_locale(): void
    {
        /*
         * The positive control for the two tests above. Both of them pass just as
         * happily against a route that lost its middleware entirely, or against a
         * route that stopped existing in the shape they look for, so without this
         * a broken registration reads as a green suite.
         */
        foreach ($this->marketingPaths() as $path) {
            $middleware = $this->middlewareFor($path);

            $this->assertContains(
                SubstituteBindings::class,
                $middleware,
                "GET {$path} no longer binds route parameters.",
            );

            $this->assertContains(
                SetMarketingLocale::class,
                $middleware,
                "GET {$path} no longer applies the language its URL asks for.",
            );
        }
    }

    /**
     * The paths a visitor reads the marketing site on.
     *
     * Derived from the same config the routes are registered from, so adding a
     * language extends this test rather than leaving the new URL unasserted.
     *
     * @return list<string>
     */
    protected function marketingPaths(): array
    {
        $supported = array_values((array) config('magic-starter.supported_locales', []));
        $default = (string) config('app.default_locale');

        return array_values(array_merge(
            // The apex, plus the default locale's prefixed form. That one is a 301
            // and is easy to forget, and a redirect response carries headers like
            // any other.
            ['/', '/'.$default],
            array_map(
                fn (string $locale): string => '/'.$locale,
                array_values(array_diff($supported, [$default])),
            ),
        ));
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
            // A closure middleware would sail through every substring assertion in
            // this file without being readable at all, so fail on it here instead.
            $this->assertIsString($entry, "GET {$path} carries a middleware that is not a class name.");
        }

        return array_values($middleware);
    }

    /**
     * The route that actually answers a GET of the given path.
     *
     * Resolved by matching a request rather than by route name: the default
     * locale's 301 has no name, and matching is also what a visitor's request
     * does, so a route that exists but is shadowed by another cannot pass.
     */
    protected function routeFor(string $path): Route
    {
        return app('router')->getRoutes()->match(Request::create($path, 'GET'));
    }
}
