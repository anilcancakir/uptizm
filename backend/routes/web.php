<?php

use App\Http\Controllers\StatusPage\ShowStatusPageController;
use App\Http\Controllers\StatusPage\SubscribeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Public status pages.
 *
 * These run on the lean `static` middleware group (SubstituteBindings only, no
 * session / cookies) so the rendered page stays CDN-cacheable and pays no
 * session cost. The show route additionally throttles per IP to blunt slug
 * enumeration (`resource-not-found` limiter).
 *
 * All four route NAMES are registered here so the Blade layout can generate its
 * URLs via `route('status.subscribe', $slug)` etc. before the SubscribeController
 * lands. Laravel resolves a controller action only on dispatch, so pointing the
 * subscribe routes at the not-yet-written controller is safe: they exist for
 * name-based URL generation until that controller ships.
 */
Route::middleware('static')->group(function (): void {
    Route::get('/s/{slug}', ShowStatusPageController::class)
        ->middleware('throttle:resource-not-found')
        ->name('status.show');

    Route::post('/s/{slug}/subscribe', [SubscribeController::class, 'store'])
        ->name('status.subscribe');

    Route::get('/s/{slug}/subscribe/confirm/{token}', [SubscribeController::class, 'confirm'])
        ->name('status.subscribe.confirm');

    Route::get('/unsubscribe/{token}', [SubscribeController::class, 'unsubscribe'])
        ->name('status.unsubscribe');
});
