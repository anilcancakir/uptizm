<?php

use App\Http\Controllers\StatusPage\ShowStatusPageController;
use App\Http\Controllers\StatusPage\SubscribeController;
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
