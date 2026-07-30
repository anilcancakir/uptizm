<?php

use App\Http\Controllers\StatusPage\ShowStatusPageController;
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
        // nginx is the only client that reaches this app. It terminates TLS and
        // rewrites the client address from Cloudflare's `CF-Connecting-IP`, then
        // SETS `X-Forwarded-For` to exactly that address (see deploy/vhost.conf).
        //
        // Trust the loopback ONLY. Trusting `*` would accept a client-supplied
        // `X-Forwarded-For`, and Cloudflare APPENDS the real address to whatever
        // the client sent rather than replacing it, so the leftmost entry is
        // attacker controlled. Every IP-keyed limiter registered in `booted()`
        // below, including the slug-enumeration cap, depends on this.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
        ]);

        // Lean group for the public status pages: bind route params but skip
        // StartSession / cookies so the page carries no Set-Cookie and stays
        // CDN-cacheable with zero session cost.
        $middleware->group('static', [
            SubstituteBindings::class,
        ]);

        // The Stripe webhook lives in the `web` group (StripeWebhookController)
        // but arrives with no CSRF token, so exempt the whole `stripe/*` path.
        // Authenticity is enforced by Cashier's VerifyWebhookSignature instead.
        $middleware->validateCsrfTokens(except: [
            'stripe/*',
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
        //
        // A headless preview render fetches the app's own origin, so it shares
        // one per-IP bucket with real visitor traffic and could be 429'd by it,
        // which would then be screenshotted and stored as the customer view. The
        // relief is keyed on a VALID preview token (which only the renderer
        // holds) and NOT on the source address: behind a proxy without
        // TrustProxies configured a real visitor is indistinguishable from the
        // app's own host, so an address-based exemption would remove
        // enumeration protection for everyone.
        //
        // Relief is a SEPARATE bucket, not an exemption. Keyed on the requested
        // page, it can never be consumed by visitor traffic, while the token
        // path stays bounded: it bypasses the 60s cache and rebuilds the whole
        // read model per request, and the token is never rotated or revocable.
        // A legitimate render issues one request per dispatch, far below this.
        // The relief bucket is keyed on the resolved SLUG, not the request path:
        // the same page is reachable as `/s/{slug}` and as `{slug}.<host>`, whose
        // path is `/` for every page, so a path key would collapse every
        // subdomain render into one shared bucket.
        RateLimiter::for(
            'resource-not-found',
            fn (Request $request) => ShowStatusPageController::requestCarriesValidPreviewToken($request)
                ? Limit::perMinute(30)->by('status-page-preview:'.$request->route('slug'))
                : Limit::perMinute(30)->by($request->ip()),
        );

        // Bound the preview-render trigger. This limiter is REQUIRED, not
        // defensive: `api/v1` never calls throttleApi(), and the render job is
        // unique only UNTIL PROCESSING (so an edit during a render can queue a
        // follow-up), which means nothing else caps how fast an operator can ask
        // for a Chromium spawn.
        //
        // Two buckets, mirroring the subscribe limiter below. The actor bucket
        // holds one member accountable; the page bucket bounds the aggregate,
        // since a whole team refreshing the same page would otherwise multiply
        // the actor limit by the number of members. `$request->user()` resolves a
        // TOKEN request here because `config/auth.php` makes `sanctum` the
        // default guard, so it asks the token guard rather than a session; the
        // address fallback covers a request whose token failed, which is counted
        // before it is rejected. A test pins the per-actor keying, because a
        // silent fall back to the address would put one office NAT on a single
        // render budget.
        RateLimiter::for(
            'status-page-preview-render',
            fn (Request $request) => [
                Limit::perMinute(10)->by('actor:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
                Limit::perMinute(20)->by('page:'.$request->path()),
            ],
        );

        // Bound the signed preview-image route. It is unauthenticated by
        // necessity (see routes/api.php), so the source address is the only key
        // available. The ceiling is generous because a legitimate client fetches
        // one image per render: the URL is stable between renders, so its image
        // cache answers every subsequent read.
        RateLimiter::for(
            'status-page-preview-image',
            fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()),
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
