<?php

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * The apex host. Deliberately a blank slate: the landing page is being rebuilt
 * from scratch, section by section, starting with the hero.
 *
 * Keep this route registered even while empty. It is what answers on the apex,
 * and `SubdomainAddressingTest` pins that it wins there while the host-constrained
 * status-page route still wins on a subdomain, which is a routing contract worth
 * not breaking mid-rebuild.
 */
Route::get('/', fn () => view('landing'))->name('landing');

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
