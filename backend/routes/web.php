<?php

use App\Http\Controllers\Marketing\ShowLandingController;
use App\Http\Controllers\Marketing\ShowRobotsController;
use App\Http\Controllers\Marketing\ShowSitemapController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', ShowLandingController::class)->name('landing');

/*
 * Crawler files come from routes, not from public/, so the hostname inside them
 * is derived from `app.url` rather than hardcoded. There must be no
 * public/robots.txt or public/sitemap.xml, since nginx's try_files would serve
 * the file and these routes would never run.
 */
Route::get('robots.txt', ShowRobotsController::class)->name('robots');
Route::get('sitemap.xml', ShowSitemapController::class)->name('sitemap');

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
