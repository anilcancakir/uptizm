<?php

use App\Http\Controllers\StatusPage\ShowStatusPageController;
use App\Http\Controllers\StatusPage\SubscribeController;
use App\Http\Middleware\RejectReservedStatusPageSlug;
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
 * All four route names are defined here; the show route additionally throttles
 * per IP to blunt slug enumeration (`resource-not-found` limiter).
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
        ->get('/', ShowStatusPageController::class)
        ->name('status.show.subdomain');
}
