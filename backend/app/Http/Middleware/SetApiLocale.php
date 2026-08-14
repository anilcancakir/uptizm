<?php

namespace App\Http\Middleware;

use Closure;
use FlutterSdk\MagicStarter\Http\Controllers\AuthController;
use FlutterSdk\MagicStarter\Support\RequestLocaleDetector;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers an API request in the caller's own language.
 *
 * The gap this closes was invisible for a long time, and the reason is worth
 * stating: the Flutter client localizes ITSELF out of `assets/lang/*.json`, so
 * every string the API sends is either a key the client translates or a value it
 * formats. Nothing needed the server to speak Turkish, with one exception, and
 * it is the whole AI surface: that text is GENERATED here, and no client
 * catalogue can reach it. An operator whose entire interface was Turkish read an
 * English incident analysis, and `Accept-Language: tr` on the draft endpoint
 * returned English as well, measured against the live provider rather than
 * assumed.
 *
 * `RequestLocaleDetector` was already in the codebase and was already doing the
 * hard part. It just ran in one place: REGISTRATION, where it writes
 * `users.locale` once ({@see AuthController}).
 * Capturing a preference and applying it are two different things, and only the
 * first had shipped.
 *
 * Registered on the `api` group in `bootstrap/app.php`. Deliberately NOT on the
 * marketing or status-page groups: {@see SetMarketingLocale} owns the first, and
 * a public status page publishes in ONE language chosen by its owner, on purpose,
 * because a page that redirects on browser language shows a crawler one language
 * and traps the visitor who wanted the other.
 */
class SetApiLocale
{
    /**
     * Apply the caller's language for the rest of the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        return $next($request);
    }

    /**
     * The language this request should be answered in.
     *
     * Three sources in a deliberate order:
     *
     * 1. The authenticated user's stored `locale`. It outranks the header
     *    because it is the choice they made in the settings screen, and a phone
     *    left on English must not override it.
     * 2. The negotiated `Accept-Language`, for register and the other endpoints
     *    that have no user yet. Delegated rather than reimplemented: the
     *    detector already handles quality weights, region subtags, and matching
     *    against `magic-starter.supported_locales`, and it is the same
     *    negotiation that wrote the stored value in the first place.
     * 3. The configured default.
     *
     * `?:` and not `??` at the first step: the column is NOT NULL with an `'en'`
     * default, so null never arrives and an EMPTY string is the value that does.
     * `??` would accept it as a preference and hand a model an empty language.
     *
     * An unsupported language cannot pass: the detector returns null for
     * anything outside the shipped list, so a `de` browser is answered in the
     * default rather than in a language with no catalogue and no
     * `PromptLanguage` name.
     */
    protected function resolve(Request $request): string
    {
        $stored = $request->user()?->locale;

        return (is_string($stored) ? $stored : '')
            ?: RequestLocaleDetector::detectLocale($request)
            ?: (string) config('app.locale');
    }
}
