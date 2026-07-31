<?php

use App\Http\Controllers\Marketing\ShowLandingController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\SetMarketingLocale;
use Illuminate\Support\Facades\Route;

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
 * the host-constrained status-page route still wins on a subdomain.
 */
$supportedLocales = array_values((array) config('magic-starter.supported_locales', []));
$defaultLocale = (string) config('app.default_locale');
$prefixedLocales = array_values(array_diff($supportedLocales, [$defaultLocale]));

Route::middleware(SetMarketingLocale::class)->group(function () use ($defaultLocale, $prefixedLocales): void {
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
});

/*
 * Point Cashier's `stripe/webhook` path at the app's StripeWebhookController so
 * every delivery runs the idempotent dedupe + entitlement projection. This
 * registration overrides Cashier's default route (same URI, loaded later). It
 * inherits the `web` group, so `stripe/*` is CSRF-exempt in bootstrap/app.php;
 * Cashier still attaches VerifyWebhookSignature when the webhook secret is set.
 */
Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');

/*
 * The public status-page routes live in routes/status.php, registered by the
 * bootstrap `then` callback OUTSIDE the `web` middleware group, so they carry
 * no session or CSRF (a web.php route would otherwise inherit the `web` group
 * and 419 the public subscribe POST). See routes/status.php.
 */
