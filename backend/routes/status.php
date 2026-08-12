<?php

use App\Http\Controllers\StatusPage\ShowStatusPageController;
use App\Http\Controllers\StatusPage\SubscribeController;
use App\Http\Middleware\RejectReservedStatusPageSlug;
use App\Support\StatusPages\StatusPageLocale;
use Illuminate\Support\Facades\Route;

/*
 * Public status pages, registered OUTSIDE the `web` middleware group (via the
 * bootstrap `then` callback under the lean `static` group), so they carry no
 * session and no CSRF token. This is deliberate: the page must stay
 * CDN-cacheable (no Set-Cookie), and the subscribe POST is a public
 * unauthenticated write with no victim session to forge, so CSRF adds session
 * cost for no gain. The real abuse mitigations are the per-IP + per-email
 * throttle, the pending-dedupe, and the double-opt-in confirm token.
 *
 * Every `status.*` route name is defined here; each route that RENDERS a page
 * additionally throttles per IP to blunt slug enumeration (`resource-not-found`
 * limiter), the locale-prefixed forms below included: they address the same rows
 * through the same controller, so leaving one unmetered would hand an enumerator
 * a second budget for the same sweep.
 */
Route::get('/s/{slug}', ShowStatusPageController::class)
    ->middleware('throttle:resource-not-found')
    ->name('status.show');

Route::post('/s/{slug}/subscribe', [SubscribeController::class, 'store'])
    ->middleware('throttle:status-subscribe')
    ->name('status.subscribe');

Route::get('/s/{slug}/subscribe/confirm/{token}', [SubscribeController::class, 'confirm'])
    ->name('status.subscribe.confirm');

Route::get('/unsubscribe/{token}', [SubscribeController::class, 'unsubscribe'])
    ->name('status.unsubscribe');

/*
 * The same page, one URL per language.
 *
 * The language a page publishes in keeps the unprefixed URL above and gets NO
 * prefixed form (and no redirect between the two), so every language has exactly
 * ONE address and hreflang has a single canonical to name for each. WHICH
 * language that is comes from `status_pages.locale`, a fact about the page, and a
 * route cannot know which page it is about: the segment therefore accepts EVERY
 * supported language, `app.default_locale` included, and the controller refuses
 * the one prefix that would duplicate the page's own bare URL. Subtracting the
 * deployment default here instead left English with no address at all on a page
 * published in Turkish, while that page's head advertised one.
 *
 * The list is {@see StatusPageLocale::supported()}, which is
 * `magic-starter.supported_locales` with `app.default_locale` in front of it: the
 * same array the API negotiates Accept-Language against and
 * `routes/marketing.php` builds its own prefixes from, read through the one
 * accessor the chrome's alternates and the language offer also read, so a
 * language cannot be advertised on the page and missing from the URL space.
 *
 * Registered only when there IS more than one language, because `whereIn([])`
 * compiles to an empty alternation that matches ANYTHING: on a single-language
 * deployment the constraint would stop constraining and `/xx/s/{slug}` would
 * answer the page at unbounded addresses. That deployment has nothing to prefix
 * either way, since its one language is the one on the bare URL of every page.
 *
 * The constraint is `whereIn` on the route and deliberately never
 * `Route::pattern()`, which binds `{locale}` for every route in the application
 * rather than for these two.
 *
 * `{locale}` comes FIRST in this URI, so the controller has to read the slug off
 * the route BY NAME: route parameters are passed to the action in URI order, so
 * a positional `__invoke(Request $request, string $slug)` receives the LANGUAGE
 * on `/tr/s/acme`. `Marketing\ShowServiceStatusController::__invoke()` carries
 * the same note for the same reason on `/{locale}/status/{slug}`.
 *
 * The subscribe, confirm and unsubscribe routes above stay locale-free: they are
 * writes and token redemptions rather than indexable documents, so a second
 * address would buy them nothing.
 */
$supportedLocales = StatusPageLocale::supported();

if (count($supportedLocales) > 1) {
    Route::get('/{locale}/s/{slug}', ShowStatusPageController::class)
        ->whereIn('locale', $supportedLocales)
        ->middleware('throttle:resource-not-found')
        ->name('status.show.localized');
}

/*
 * The same page, addressed as `{slug}.<subdomain_host>` instead of a path.
 *
 * Registered only when `status_pages.subdomain_host` is configured: a wildcard
 * host route makes every unclaimed label under the domain app-reachable, so it
 * is opt-in per environment rather than derived from APP_URL.
 *
 * `domain_mode` on the row does NOT gate this. The column records which URL the
 * operator wants to hand out, and both URLs answering is what makes switching
 * modes non-breaking (a customer's old link keeps working). What the mode drives
 * is the canonical URL the app advertises, not what resolves.
 *
 * Ordering matters: this must come AFTER the `/s/...` routes so a request to
 * `<host>/s/acme` is matched by the path route rather than falling into a host
 * pattern, and the reserved-label filter runs before the controller so a
 * reserved slug never reaches a database lookup.
 */
$subdomainHost = config('status_pages.subdomain_host');

if (is_string($subdomainHost) && $subdomainHost !== '') {
    Route::domain('{slug}.'.$subdomainHost)
        ->middleware([RejectReservedStatusPageSlug::class, 'throttle:resource-not-found'])
        ->group(function () use ($supportedLocales): void {
            Route::get('/', ShowStatusPageController::class)
                ->name('status.show.subdomain');

            /*
             * The prefixed form of the same page, so a language has one address on
             * whichever hostname the page is canonical on.
             *
             * The `whereIn` carries more weight here than on the path form above.
             * This route lives in the HOST-CONSTRAINED bucket, which
             * `RouteCollection` matches BEFORE the unconstrained one, and it is a
             * bare one-segment pattern: unconstrained, `{slug}.<host>/up` would be
             * read as a language and answered by the status controller, shadowing
             * the health check nginx and the deploy script poll on every status
             * subdomain. Registered only when there is more than one language, for
             * the empty-alternation reason the path form documents.
             *
             * That same bucket precedence is why the list here must include
             * `app.default_locale`: `routes/marketing.php` registers
             * `Route::redirect('/en', '/', 301)` with no domain constraint, so
             * while this route excluded the default the redirect was what `/en`
             * resolved to on a status subdomain, sending a visitor asking for
             * English to the page's own language. Included, this claims it and
             * answers the English document (or 404s when English IS the page's own
             * language, which is the URL that must not exist twice).
             */
            if (count($supportedLocales) > 1) {
                Route::get('/{locale}', ShowStatusPageController::class)
                    ->whereIn('locale', $supportedLocales)
                    ->name('status.show.subdomain.localized');
            }
        });
}
