<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * The public status-page routes live in routes/status.php, registered by the
 * bootstrap `then` callback OUTSIDE the `web` middleware group, so they carry
 * no session or CSRF (a web.php route would otherwise inherit the `web` group
 * and 419 the public subscribe POST). See routes/status.php.
 */
