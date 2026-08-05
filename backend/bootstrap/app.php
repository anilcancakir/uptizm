<?php

use App\Http\Controllers\Api\V1\MonitorContentController;
use App\Http\Controllers\Marketing\SendContactMessageController;
use App\Http\Controllers\StatusPage\ShowStatusPageController;
use App\Http\Middleware\SetMarketingLocale;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

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
        //
        // The read-only marketing pages register the same way, and for a reason
        // the status pages do not have: ePrivacy Art. 5(3) exempts only storage
        // strictly necessary to the service the visitor asked for, assessed per
        // purpose, so a session cookie on a page with no form is not exempt and
        // the Privacy page says these pages set none. `routes/marketing.php`
        // carries that reasoning in full. It must stay its OWN file: the `web:`
        // argument above is loaded as `Route::middleware('web')->group(...)`, so
        // a route in `routes/web.php` inherits `StartSession` whatever else is
        // done to it. `SetMarketingLocale` rides along here rather than inside
        // the file so every route in it speaks the language its URL asks for; it
        // is session-free and cookie-free by design.
        then: function (): void {
            // Both files register a route for `/`: the marketing pages on the apex, the
            // status pages behind a `{slug}.<host>` domain constraint. Order between them
            // does NOT decide which wins, cached or not, and it was once reported that it
            // did. It does not: `RouteCollection` keeps host-constrained routes in their
            // own bucket and matches it first, and the compiled matcher `artisan optimize`
            // produces compiles the host regex into the match. Verified against production,
            // which runs cached routes and resolves `{slug}.uptizm.com/` to
            // `status.show.subdomain`.
            //
            // The earlier report came from a machine where `status_pages.subdomain_host`
            // was unset, so the subdomain route was never registered at all and the apex
            // route answered by default. That is a configuration difference, not a routing
            // defect. `SubdomainAddressingTest` now compiles the collection and asserts the
            // precedence with the host configured, so the question cannot be reopened by
            // whoever next runs the suite on an unconfigured machine.
            Route::middleware(['static', SetMarketingLocale::class])
                ->group(base_path('routes/marketing.php'));

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
        /*
         * The submitted email address as a LIMITER KEY, and the reason it is a closure rather
         * than a cast at each call site.
         *
         * `$request->input('email')` is attacker-controlled and `email[]=x` arrives as an
         * ARRAY, so `(string) $request->input('email')` raises "Array to string conversion".
         * `HandleExceptions` promotes that warning to an `ErrorException` INSIDE the limiter
         * closure, which runs before any controller, so a one-line curl against either public
         * write path below was a 500 plus a stack trace in the log, for free, unauthenticated.
         * `SendContactMessageController::stringInput()` was already careful about exactly this
         * input (`is_string`, never a cast) for exactly this reason; the limiters were not.
         *
         * `is_string` and not `(string)`: an array collapses to the empty key, which is the
         * same bucket a request with no email at all lands in, and the `email` validation rule
         * downstream refuses the value anyway.
         *
         * Lower-cased and trimmed so `A@x.test` and ` a@x.test ` share one bucket instead of
         * buying a second for the same inbox. Both endpoints below want that: one mails the
         * submitted address, the other rate-limits by it.
         */
        $submittedEmailKey = static function (Request $request): string {
            $email = $request->input('email');

            return is_string($email) ? Str::lower(trim($email)) : '';
        };

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

        // Bound the two endpoints that read a monitor's newest ARCHIVED body:
        // the extraction-candidate browser and the metric-form preview. REQUIRED
        // for the same reason as the render trigger above, plus a cost the others
        // do not have: one accepted request is a cold read off a FUSE mount of a
        // Drive remote, roughly a second against a remote capped near two file
        // operations a second, and an Octane worker is held for the whole read.
        // Both routes carry this one limiter (see routes/api.php), because they
        // read the SAME blob and a separate budget each would double the rate a
        // single operator can spend on it.
        //
        // Same two buckets as the render trigger, and for the same reasons.
        // `$request->user()` resolves a TOKEN request because `config/auth.php`
        // makes `sanctum` the default guard; the address fallback covers a request
        // whose token failed, counted before it is rejected. The aggregate bucket
        // is keyed on `$request->path()`, which carries the monitor id and is
        // always a string: a route PARAMETER is not safe to reach for here,
        // because `ThrottleRequests` sorts ahead of `SubstituteBindings` today and
        // behind it after any priority change, so the value would silently switch
        // between a string and a model. Keying on the path also gives each of the
        // two routes its own aggregate bucket per monitor, which is what a whole
        // team browsing one monitor's candidates should not be able to multiply.
        RateLimiter::for(
            MonitorContentController::SAMPLE_READ_LIMITER,
            fn (Request $request) => [
                Limit::perMinute(10)->by('actor:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
                Limit::perMinute(20)->by('monitor-sample:'.$request->path()),
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
        //
        // The email key goes through `$submittedEmailKey` at the top of this
        // callback and NEVER through a cast: `email[]=x` on this endpoint was a
        // 500 for the same one-line reason the contact form's was, and this one
        // has been live longer.
        RateLimiter::for(
            'status-subscribe',
            fn (Request $request) => [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perMinute(5)->by($submittedEmailKey($request)),
            ],
        );

        // Bound the operator-side subscriber add. REQUIRED for the same reason the
        // preview-render trigger above is: `api/v1` never calls throttleApi(), so
        // nothing else caps how fast an authenticated member can spend this
        // endpoint, and since the add now mints an unconfirmed row and queues a
        // confirmation mail, each accepted request is one message to a THIRD
        // PARTY. The plan's per-page cap bounds the total; this bounds the rate,
        // so a harvested list cannot be turned into a burst of mail from the
        // product's own sending domain.
        //
        // Same two buckets as the render trigger, and the actor bucket is
        // deliberately no looser: one member is held accountable, while the page
        // bucket bounds the aggregate a whole team could otherwise multiply.
        //
        // Note what is NOT a key here. This route carries a submitted `email`,
        // and keying on it would put an attacker-controlled value into the
        // limiter, which is where `email[]=x` became an unauthenticated 500 plus
        // a stack trace on the two public write paths (see `$submittedEmailKey`
        // at the top of this callback). It buys nothing on this route either: the
        // actor is already known and is the accountable party, so the submitted
        // value never reaches a key at all. The page key is `$request->path()`,
        // which carries the page id and is always a string; a route PARAMETER
        // would not be safe to reach for, because `ThrottleRequests` sorts ahead
        // of `SubstituteBindings` today and behind it after any priority change,
        // so the value would silently switch between a string and a model.
        RateLimiter::for(
            'status-page-subscriber-add',
            fn (Request $request) => [
                Limit::perMinute(10)->by('actor:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
                Limit::perMinute(20)->by('page:'.$request->path()),
            ],
        );

        // The public contact form. TWO buckets here, and a third that deliberately
        // is NOT here.
        //
        // WHY THE AGGREGATE CAP LIVES IN THE CONTROLLER AND NOT ON THIS ROUTE
        //
        // The contact form mails the OPERATOR, so varying the submitted address
        // costs an attacker nothing while the target inbox stays the same one
        // either way. A fixed global key is therefore the only bound on a
        // distributed flood, and for a while it was registered right here as a
        // third bucket. That made it a KILL SWITCH for the site's only contact
        // channel: `ThrottleRequests::handleRequest()` checks every bucket and
        // then hits every bucket, before the controller runs and whatever the
        // controller decides, so a REJECTED submission spent the global slot
        // exactly like an accepted one. Thirty garbage POSTs a minute closed the
        // form for every visitor, and since this route carries no CSRF token (and
        // cannot), any third-party page could make real visitors' browsers issue
        // them from residential addresses the per-IP bucket cannot separate.
        //
        // So the aggregate cap moved to `SendContactMessageController`, which
        // spends a slot only once a submission has actually passed validation and
        // is about to be queued, and answers an exhausted budget with the CONTACT
        // PAGE carrying the fallback address rather than the framework's 429 error
        // page. Do not put a global bucket back here.
        //
        // These two stay: they are meant to bite on garbage, and each bounds one
        // host or one submitted address rather than the whole channel. The per-IP
        // key depends on the `trustProxies` decision at the top of this file,
        // exactly as the others do, and this is registered on `booted` for the same
        // reason as every limiter above (the RateLimiter facade root is not set
        // while the middleware configuration closure runs).
        RateLimiter::for(
            SendContactMessageController::LIMITER,
            fn (Request $request) => [
                Limit::perMinute(5)->by('contact-form:ip:'.$request->ip()),
                // Prefixed so a submitted value can never collide with another
                // bucket's key space, and normalised by `$submittedEmailKey` above,
                // which is also what keeps `email[]=x` from being a 500 here.
                Limit::perMinute(5)->by('contact-form:email:'.$submittedEmailKey($request)),
            ],
        );
    })
    ->create();
