<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Public status pages register OUTSIDE the `web` group (no session /
        // CSRF) under the lean `static` group, so the page stays CDN-cacheable
        // and the public subscribe POST is not CSRF-gated (a web.php route would
        // inherit the `web` group and 419 the POST). Throttle + double opt-in +
        // dedupe are the real mitigations.
        then: function (): void {
            Route::middleware('static')->group(base_path('routes/status.php'));
        },
    )
    // Register the broadcasting auth route at `api/v1/broadcasting/auth` behind
    // the Sanctum guard, NOT the default web/session/CSRF group. The Flutter
    // Echo client dials `<base>/api/v1` + `/broadcasting/auth` (Dio concatenates
    // baseUrl + path), so the prefix MUST be `api/v1` and the guard must accept
    // the Sanctum bearer token, not a session cookie.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        [
            'prefix' => 'api/v1',
            'middleware' => ['api', 'auth:sanctum'],
        ],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Lean group for the public status pages: bind route params but skip
        // StartSession / cookies so the page carries no Set-Cookie and stays
        // CDN-cacheable with zero session cost.
        $middleware->group('static', [
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })
    ->booted(function (): void {
        // Throttle slug enumeration on the public status route: a private page
        // 404s like a non-existent one, so this per-IP cap stops attackers from
        // sweeping the slug space to discover which pages exist. Registered on
        // `booted` because the RateLimiter facade root is not yet set while the
        // middleware configuration closure runs.
        RateLimiter::for(
            'resource-not-found',
            fn (Request $request) => Limit::perMinute(30)->by($request->ip()),
        );

        // Throttle the public subscribe write per IP AND per submitted email, so
        // neither a single host nor a single targeted address can be used to
        // spray confirm mail or brute the endpoint. Both limits must pass.
        RateLimiter::for(
            'status-subscribe',
            fn (Request $request) => [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perMinute(5)->by((string) $request->input('email')),
            ],
        );
    })
    ->create();
