<?php

namespace Tests\Feature\Http;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Tests\TestCase;

/**
 * Covers the production defect measured against `api.uptizm.com`: a guest
 * `api/v1` request that does NOT send `Accept: application/json` answered
 * 500 instead of 401.
 *
 * The root cause sits one layer below `withExceptions()`. The framework's
 * own `ApplicationBuilder::withMiddleware()` unconditionally calls
 * `redirectGuestsTo(fn () => route('login'))` before this app's closure in
 * `bootstrap/app.php` runs, so `Illuminate\Auth\Middleware\Authenticate`
 * resolves that redirect target while CONSTRUCTING the `AuthenticationException`
 * it is about to throw (`Authenticate::unauthenticated()` calls
 * `$this->redirectTo($request)` as a constructor argument, before the
 * `throw` statement executes). This app has no route named `login`, so that
 * call raised `RouteNotFoundException`, which replaced the
 * `AuthenticationException` entirely: the exception handler never rendered
 * an authentication failure, it rendered an unrelated routing exception as a
 * JSON 500 (`shouldRenderJsonWhen` already covers the render FORMAT, which
 * is why the body was JSON and the diagnosis in the task brief calls that
 * mechanism a dead end).
 *
 * A `render()` callback typed to `AuthenticationException` cannot fix this:
 * by the time `Handler::render()` runs, the exception in hand is already
 * `RouteNotFoundException`. The fix has to sit at the point the redirect
 * target is resolved, so `bootstrap/app.php` now scopes `redirectGuestsTo()`
 * to answer `null` for `api/*` guests, leaving `AuthenticationException`'s
 * `redirectTo` empty; the handler's own `unauthenticated()` then falls
 * through to `shouldReturnJson()`, which the existing `shouldRenderJsonWhen`
 * callback already answers `true` for `api/*` regardless of `Accept`.
 */
class UnauthenticatedApiRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The reproducer: no `Accept` header at all, exactly the shape that hit
     * production (a browser navigation or a bare curl, not a JSON client).
     */
    public function test_a_guest_api_request_with_no_accept_header_answers_401_json(): void
    {
        $response = $this->get('/api/v1/incidents');

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    /**
     * The already-correct case, kept as a sibling assertion so a regression
     * that fixes one Accept shape by breaking the other is caught here too.
     */
    public function test_a_guest_api_request_with_accept_json_answers_401_json(): void
    {
        $response = $this->getJson('/api/v1/incidents');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    /**
     * Proves the scoping without inventing a non-existent route: the same
     * registered callback still calls `route('login')` for a request outside
     * `api/*`, which still throws `RouteNotFoundException` because this app
     * genuinely has no such route. That is the framework's UNCHANGED default
     * behaviour for a guest outside the API, not a regression this fix
     * introduces; the fix only short-circuits the `api/*` branch before that
     * call is ever reached.
     */
    public function test_the_redirect_default_is_untouched_outside_api(): void
    {
        $reflection = new \ReflectionClass(Authenticate::class);
        $callback = $reflection->getStaticPropertyValue('redirectToCallback');

        $this->assertIsCallable($callback);
        $this->expectException(RouteNotFoundException::class);

        $callback(Request::create('/dashboard'));
    }
}
