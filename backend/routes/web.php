<?php

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * This file is loaded as `Route::middleware('web')->group(...)`, so EVERY route
 * registered here starts a session, encrypts cookies and gates on a CSRF token,
 * with no per-route way out. That is right for the Stripe webhook below and wrong
 * for a page a visitor merely reads.
 *
 * So the public marketing pages are NOT here: they live in `routes/marketing.php`,
 * registered by the bootstrap `then` callback under the lean `static` group, and
 * they set no cookie at all. That file carries the ePrivacy reasoning in full.
 * Adding a marketing route here instead would re-inherit the session silently and
 * put two `Set-Cookie` headers back on a formless page, which the Privacy page
 * states does not happen.
 */

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
