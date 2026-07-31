<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the language a marketing URL asks for.
 *
 * The locale is carried by the PATH and by nothing else: no cookie, no session,
 * no Accept-Language negotiation. That is a deliberate divergence from the Flutter
 * client, which does auto-detect the device language. A public page that redirects
 * on browser language shows a crawler only one of its languages and traps a visitor
 * who wants the other, so here each language gets a stable URL, an hreflang
 * alternate and a visible switcher instead.
 *
 * The locale is set on EVERY request through this group, including the default, and
 * that is load bearing rather than tidy: under Octane the translator singleton
 * survives between requests, so setting it only for `tr` would leave the worker in
 * Turkish for whoever arrives next. `FlushLocaleState` in config/octane.php closes
 * the same hole from the other side.
 */
class SetMarketingLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->route('locale');
        $supported = (array) config('magic-starter.supported_locales', []);

        app()->setLocale(
            is_string($requested) && in_array($requested, $supported, true)
                ? $requested
                : (string) config('app.default_locale'),
        );

        return $next($request);
    }
}
