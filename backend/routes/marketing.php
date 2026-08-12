<?php

use App\Http\Controllers\Marketing\SendContactMessageController;
use App\Http\Controllers\Marketing\ShowBotController;
use App\Http\Controllers\Marketing\ShowContactController;
use App\Http\Controllers\Marketing\ShowFaqController;
use App\Http\Controllers\Marketing\ShowLandingController;
use App\Http\Controllers\Marketing\ShowPrivacyController;
use App\Http\Controllers\Marketing\ShowServiceIndexController;
use App\Http\Controllers\Marketing\ShowServiceStatusController;
use App\Http\Controllers\Marketing\ShowSitemapController;
use App\Http\Controllers\Marketing\ShowTermsController;
use App\Services\Services\SitemapBuilder;
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

/*
 * The long-form documents: Privacy, Terms, Contact, FAQ.
 *
 * Registered here rather than in `routes/web.php`, and that is not a matter of tidiness:
 * the `web:` argument in `bootstrap/app.php` is loaded as
 * `Route::middleware('web')->group(...)`, so a route in THAT file re-inherits
 * `StartSession` whatever was done to its neighbours. The Privacy page below states that
 * these pages store nothing on the visitor's device, so a session cookie here would
 * falsify a published claim. `tests/Feature/Marketing/LegalPagesTest.php` asserts the
 * resolved middleware of every path added here, because the failure is otherwise
 * invisible until somebody curls for a `Set-Cookie` header.
 *
 * The paths mirror the landing shape exactly, per language: the default language on the
 * apex, every other language behind its prefix, one URL per language per document so
 * hreflang has a single canonical to name. The controller constant, the Markdown filename
 * under `resources/legal/` and the path key below are the same string.
 */
$documents = [
    'privacy' => ShowPrivacyController::class,
    'terms' => ShowTermsController::class,
    'contact' => ShowContactController::class,
    'faq' => ShowFaqController::class,
    // The contact point `config('uptizm.bot_user_agent')` advertises on every feed
    // request the service catalog makes. It has to resolve: an operator who does not
    // want us reading their feed needs somewhere to find out who we are and say so.
    'bot' => ShowBotController::class,
];

foreach ($documents as $page => $controller) {
    Route::get('/'.$page, $controller)->name($page);

    // The default language already has this document on the apex, so its prefixed form is
    // a permanent redirect rather than a second English page competing for the same query.
    // A plain `Route::redirect`, which the framework answers without touching a session.
    Route::redirect('/'.$defaultLocale.'/'.$page, '/'.$page, 301);

    /*
     * The same `whereIn` constraint the landing route carries, for a related reason. It
     * cannot swallow `/up` (that path is one segment and this pattern is two), but
     * unconstrained it would answer `/de/privacy`, `/xx/privacy` and everything else with
     * the English document under a 200, which publishes one legal notice at unbounded
     * addresses and invites a crawler to index each of them. A language we do not speak
     * must be a 404. Registered only when there IS another language, because `whereIn([])`
     * compiles to an empty alternation that matches anything at all.
     */
    if ($prefixedLocales !== []) {
        Route::get('/{locale}/'.$page, $controller)
            ->whereIn('locale', $prefixedLocales)
            ->name($page.'.localized');
    }
}

/*
 * The public service catalog: the hub at `/status`, and one page per PUBLISHED
 * service at `/status/{slug}`.
 *
 * The paths mirror the documents above exactly, per language: the default language
 * on the apex, every other language behind its prefix, the default language's
 * prefixed form a 301 so there is one URL per language per page for hreflang to
 * name. `Route::redirect` substitutes the `{slug}` into its destination (the
 * framework's `RedirectController` binds the destination as a route and passes the
 * matched parameters through), so the redirect keeps the visitor on the service
 * they asked for instead of dropping them on the hub.
 *
 * THE CONSTRAINT IS ON THE LOCALE, AND ON NOTHING ELSE
 *
 * The `whereIn` is the same one every localized route here carries, for the same
 * reason: unconstrained, `/{locale}/status` would answer `/de/status` and
 * `/xx/status` with the English page under a 200, which invites a crawler to index
 * one document at unbounded addresses, and the one-segment form would be a bare
 * two-letter catch-all that swallows `/up`, the health check nginx and the deploy
 * script poll.
 *
 * What is deliberately NOT here is a `whereIn` built from the published slugs.
 * This file runs on EVERY console boot, so a query here would hit `services`
 * before that table exists during a fresh `php artisan migrate`, and
 * `php artisan optimize` would freeze the slug list into the route cache so a
 * newly published service 404s until the next deploy. The slug carries a static
 * SHAPE pattern instead, and publication (plus the terms review) is enforced in
 * the controller with `abort_if(..., 404)`, exactly as `/s/{slug}` gates itself.
 *
 * The route NAMES are `services.index` / `services.show`. Not `status.show`: that
 * name belongs to the customer status page (`routes/status.php:22`) and is read by
 * `StatusPagePreviewRenderer` and `status/partials/footer.blade.php`, so
 * re-registering it would silently retarget an existing named route and break the
 * customer preview.
 *
 * Both are throttled by the named limiter `ShowServiceStatusController::LIMITER`
 * registered in `bootstrap/app.php`; that constant's docblock explains why this
 * reuses the existing bucket rather than adding one here.
 */
Route::get('/'.ShowServiceStatusController::PATH, ShowServiceIndexController::class)
    ->middleware('throttle:'.ShowServiceStatusController::LIMITER)
    ->name('services.index');

Route::redirect('/'.$defaultLocale.'/'.ShowServiceStatusController::PATH, '/'.ShowServiceStatusController::PATH, 301);

if ($prefixedLocales !== []) {
    Route::get('/{locale}/'.ShowServiceStatusController::PATH, ShowServiceIndexController::class)
        ->whereIn('locale', $prefixedLocales)
        ->middleware('throttle:'.ShowServiceStatusController::LIMITER)
        ->name('services.index.localized');
}

Route::get('/'.ShowServiceStatusController::PATH.'/{slug}', ShowServiceStatusController::class)
    ->where('slug', ShowServiceStatusController::SLUG_PATTERN)
    ->middleware('throttle:'.ShowServiceStatusController::LIMITER)
    ->name('services.show');

Route::redirect(
    '/'.$defaultLocale.'/'.ShowServiceStatusController::PATH.'/{slug}',
    '/'.ShowServiceStatusController::PATH.'/{slug}',
    301,
);

if ($prefixedLocales !== []) {
    Route::get('/{locale}/'.ShowServiceStatusController::PATH.'/{slug}', ShowServiceStatusController::class)
        ->whereIn('locale', $prefixedLocales)
        ->where('slug', ShowServiceStatusController::SLUG_PATTERN)
        ->middleware('throttle:'.ShowServiceStatusController::LIMITER)
        ->name('services.show.localized');
}

/*
 * The sitemap index and its three segments.
 *
 * ONE URL each, with no locale prefix and no locale variants, because a sitemap is
 * not a page a visitor reads in a language: every entry inside it carries every
 * language as an `xhtml:link` alternate instead, composed from the same
 * `ChromeData` (or, for the status pages, the same `StatusPageChrome`) the pages
 * build their own hreflang from.
 *
 * `sitemap.xml` is the INDEX and contains no `<url>` element; the three segments
 * are the url sets. Segmenting by template is what makes an indexing problem
 * diagnosable per template in Search Console rather than as one flat list.
 *
 * The status-page segment is registered HERE, beside the other two, rather than in
 * `routes/status.php`: it is a document about customer pages, not one of them, and
 * it has to answer on the app host that `public/robots.txt` and the index name. It
 * is also why it takes no locale prefix and no subdomain form of its own.
 *
 * `robots.txt` is NOT here: it is a static file under `public/`, served by the web
 * server without touching PHP, which is also why the Laravel test client cannot
 * assert it and the QA curl does.
 */
Route::get('/'.SitemapBuilder::INDEX_PATH, [ShowSitemapController::class, 'index'])->name('sitemap.index');
Route::get('/'.SitemapBuilder::MARKETING_PATH, [ShowSitemapController::class, 'marketing'])->name('sitemap.marketing');
Route::get('/'.SitemapBuilder::SERVICES_PATH, [ShowSitemapController::class, 'services'])->name('sitemap.services');
Route::get('/'.SitemapBuilder::STATUS_PAGES_PATH, [ShowSitemapController::class, 'statusPages'])->name('sitemap.status-pages');

/*
 * The contact form's write path: the only unauthenticated write on this surface.
 *
 * It belongs in THIS file and nowhere else. The claim above ("these pages store nothing on
 * the visitor's device") covers this POST, and the Privacy page publishes it without a
 * qualifier, so a session here falsifies a published statement. The `web` group is therefore
 * out, and so is CSRF: `PreventRequestForgery` cannot exist on a session-free route at all,
 * because on the way out it always calls `$request->session()->token()` to mint `XSRF-TOKEN`
 * (`PreventRequestForgery.php:243`). What replaces the token is four layers that do not
 * depend on a session: a honeypot, an encrypted render timestamp with a minimum AND a maximum
 * age, Cloudflare Turnstile when configured, and the three-bucket limiter below. The
 * controller returns a rendered VIEW with a 422 rather than redirecting back, because a
 * redirect-with-errors on a sessionless route flashes into a store nobody saves and the
 * visitor gets an empty form with no message.
 *
 * One POST per language, mirroring the GET exactly, and that is not symmetry for its own
 * sake: `SetMarketingLocale` reads the locale from the PATH, so a Turkish page posting to
 * `/contact` would have every validation message answered in English on a Turkish page.
 *
 * `throttle:contact-form` is the only layer that bounds a distributed flood, so it is
 * attached here rather than left to the controller. Its three buckets are registered in
 * `bootstrap/app.php`; `SendContactMessageController::LIMITER` is the same string.
 */
Route::post('/contact', SendContactMessageController::class)
    ->middleware('throttle:'.SendContactMessageController::LIMITER)
    ->name('contact.send');

if ($prefixedLocales !== []) {
    Route::post('/{locale}/contact', SendContactMessageController::class)
        ->whereIn('locale', $prefixedLocales)
        ->middleware('throttle:'.SendContactMessageController::LIMITER)
        ->name('contact.send.localized');
}
