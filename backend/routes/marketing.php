<?php

use App\Http\Controllers\Marketing\ShowLandingController;
use Illuminate\Support\Facades\Route;

/*
 * The read-only marketing pages, registered OUTSIDE the `web` middleware group.
 *
 * WHY THIS IS A SEPARATE FILE, AND WHY IT HAS TO STAY ONE
 *
 * `bootstrap/app.php` hands `routes/web.php` to `withRouting(web: ...)`, and the
 * framework loads that argument as `Route::middleware('web')->group($file)`. So
 * every route in THAT FILE carries the `web` group no matter what else is done to
 * it, and `web` is `EncryptCookies` + `AddQueuedCookiesToResponse` +
 * `StartSession` + `ShareErrorsFromSession` + `PreventRequestForgery` +
 * `SubstituteBindings`. A separate file under a different group is the only
 * reliable way to a route that starts no session. That is the entire reason this
 * file exists: moving a route back into `routes/web.php` re-inherits the session
 * silently and puts two `Set-Cookie` headers back onto a page that has no form on
 * it. `tests/Feature/Marketing/CookieTest.php` is what turns red when it happens.
 *
 * WHY A COOKIE HERE IS A LEGAL PROBLEM RATHER THAN A PREFERENCE
 *
 * ePrivacy Art. 5(3) permits storing information in a visitor's terminal
 * equipment without consent only where it is strictly necessary to the service
 * THAT visitor asked for. Every regulator that has published on it (the ICO's
 * final storage-and-access guidance of April 2026, the CNIL, and KVKK's cookie
 * rehberi) assesses that necessity per PURPOSE and from the user's perspective,
 * and each says in terms that "helpful" or "convenient" is not enough. A page a
 * visitor merely reads requests no session-dependent service, so a session cookie
 * set there is not strictly necessary to that page's service, even though the
 * identical CSRF purpose IS strictly necessary on a page carrying a form.
 * Necessity is judged per purpose, so those two conclusions do not conflict; the
 * form page keeps its session, this one never had a reason for one.
 *
 * Keeping these routes off the session group takes them outside Art. 5(3)
 * altogether, and that is what lets the Privacy page state without a qualifier
 * that the read-only pages store nothing on the visitor's device, and why this
 * site needs no consent banner anywhere. The claim is published. Please do not
 * quietly falsify it by tidying this file back into `web`.
 *
 * NOTHING HERE NEEDS A SESSION
 *
 * No view under `resources/views/marketing/` and not `landing.blade.php` either
 * references `@csrf`, `csrf_token()`, `old()`, `@error`, `session()` or
 * `Session::`, and the default-locale route below is a bare 301 with no flashed
 * data (Laravel's `RedirectController` builds the response directly and never
 * touches a store). If a page ever does need a session it does not belong in this
 * file, and adding `StartSession` to this group would be worse than moving it:
 * `StartSession` without `EncryptCookies` writes a raw cookie under the same name
 * the encrypted `web` group writes, the two destroy each other, and the result is
 * an intermittent 419 that depends on which page the visitor loaded first and is
 * invisible to every test.
 *
 * The middleware is attached at the registration site in `bootstrap/app.php` (the
 * lean `static` group plus `SetMarketingLocale`), the same way
 * `routes/status.php` is, so both cookie-free surfaces are declared in one place.
 * `SetMarketingLocale` carries the locale in the PATH only: no cookie, no
 * session, no Accept-Language negotiation.
 */

/*
 * The apex host, in every language the product speaks.
 *
 * The default locale lives on the apex itself and every other locale gets a path
 * prefix, so there is exactly ONE url per language and hreflang has a single
 * canonical to name for each. The list comes from `magic-starter.supported_locales`,
 * the same array the API negotiates Accept-Language against, so a language cannot
 * appear on one surface and be missing from the other.
 *
 * Keep the apex route registered even while the page is half-built. It is what
 * answers on the apex, and `SubdomainAddressingTest` pins that it wins there while
 * the host-constrained status-page route still wins on a subdomain. That precedence
 * survived the move out of `routes/web.php` because Laravel keeps host-constrained
 * routes in their own bucket and matches it BEFORE the unconstrained one
 * (`RouteCollection::get()`), so it does not depend on which file loads first.
 */
$supportedLocales = array_values((array) config('magic-starter.supported_locales', []));
$defaultLocale = (string) config('app.default_locale');
$prefixedLocales = array_values(array_diff($supportedLocales, [$defaultLocale]));

Route::get('/', ShowLandingController::class)->name('landing');

// The default language already has a home, so its prefixed form is a permanent
// redirect rather than a second English page competing for the same query.
Route::redirect('/'.$defaultLocale, '/', 301);

/*
 * One route for every other language. The constraint is not decoration: without
 * it this is a bare two-letter catch-all, and `/up` (the health check nginx and
 * the deploy script poll) is two letters.
 *
 * Registered only when there IS another language, because `whereIn([])` compiles
 * to an empty alternation that matches anything at all.
 */
if ($prefixedLocales !== []) {
    Route::get('/{locale}', ShowLandingController::class)
        ->whereIn('locale', $prefixedLocales)
        ->name('landing.localized');
}
