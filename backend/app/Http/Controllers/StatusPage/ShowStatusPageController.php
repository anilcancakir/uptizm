<?php

namespace App\Http\Controllers\StatusPage;

use App\Http\ViewModels\StatusPageViewModel;
use App\Jobs\BustStatusPageCacheForMaintenanceBoundaries;
use App\Models\StatusPage;
use App\Services\StatusPages\StatusPageAssembler;
use App\Services\StatusPages\StatusPageCache;
use App\Support\StatusPages\StatusPageChrome;
use App\Support\StatusPages\StatusPageLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the public status page for `GET /s/{slug}`.
 *
 * This is the security spine of the public surface. Three invariants are
 * enforced here and nowhere else:
 *
 *   - Fail-closed privacy: an unknown slug and a private page are BOTH answered
 *     with a 404 (never a 403, never a distinguishable body), so a visitor can
 *     neither confirm a private page exists nor enumerate slugs by status.
 *   - One URL per language per page: the language a page publishes in is served
 *     on the UNPREFIXED URL, so this refuses that language's prefixed form with a
 *     404. The route cannot enforce it, because which language is unprefixed is a
 *     fact about the page ({@see StatusPageLocale::canonical()}) and the route
 *     segment therefore accepts every supported language.
 *   - Cache isolation: only a genuinely public page is cached, and only its
 *     `toArray()` form (never the object, which fatals under the cache store's
 *     `serializable_classes => false`). ANY request carrying a valid preview
 *     token bypasses the cache entirely (no read, no write) and is answered
 *     `no-store, private`, so a private page can never be seeded into the
 *     shared, CDN-frontable cache, and a preview or a render of a PUBLIC page
 *     reports the page as it is now rather than up to 60 seconds of stale
 *     state stamped with the current time.
 *
 * It is also where the page's LANGUAGE is applied, from the URL when it carries
 * one and from `status_pages.locale` otherwise ({@see StatusPageLocale}). The
 * whole render reads that locale implicitly (the assembler's banner label and
 * incident titles, the layout's `<html lang>`, every `__()` in the partials), so
 * this is the only place it is decided. See the numbered comments in
 * {@see self::__invoke()} for why it is set twice and why the page's own comes
 * after the privacy gate.
 */
class ShowStatusPageController
{
    /**
     * Header the preview token travels in on the render path.
     *
     * A query token is written verbatim into every access log (`artisan serve`
     * output, `pail`, nginx `$request`, Telescope), and this token is generated
     * once and never rotated, so one logged line would be indefinite read
     * access to a private page. The query parameter stays supported for the
     * seeded-URL workflow already in use.
     */
    public const string PREVIEW_TOKEN_HEADER = 'X-Preview-Token';

    /**
     * Cache-key prefix of a public page's cached read model.
     *
     * Public because the key is composed and forgotten from OUTSIDE this
     * controller: the maintenance-boundary sweep
     * ({@see BustStatusPageCacheForMaintenanceBoundaries}) busts it when a window
     * opens or closes, and a second literal over there would drift from this one
     * the day the key changes. Nothing concatenates it by hand any more;
     * {@see self::cacheKey()} and {@see self::cacheKeys()} below are how it is
     * reached.
     */
    public const string CACHE_KEY_PREFIX = 'status-page:';

    public function __construct(
        protected StatusPageAssembler $assembler,
    ) {}

    /**
     * Resolve the page behind the privacy gate and render it, caching only the
     * public path's array payload.
     *
     * @throws NotFoundHttpException
     */
    public function __invoke(Request $request): Response
    {
        // 0. Both parameters are read off the route BY NAME rather than taken
        //    positionally. Route parameters reach the action in URI ORDER, and
        //    the localized forms carry the language first (`/tr/s/acme`,
        //    `acme.<host>/tr`), so a positional `string $slug` receives the
        //    LANGUAGE there and every prefixed URL 404s.
        //    `Marketing\ShowServiceStatusController::__invoke()` carries the same
        //    note for the same reason on `/{locale}/status/{slug}`.
        $slug = (string) $request->route('slug');
        $routeLocale = $request->route('locale');

        // 1. Start every request through this route in the deployment default
        //    language, BEFORE anything can render. Under Octane the translator
        //    is a singleton that survives between requests, so a worker that
        //    just served a Turkish page is still in Turkish here; without this
        //    reset the 404 below would answer in whichever language the previous
        //    visitor's page happened to use. That matters more than tidiness on
        //    this route: the two 404s have to stay indistinguishable, and a
        //    Turkish "not found" beside an English one is a difference an
        //    enumerator can read.
        app()->setLocale((string) config('app.default_locale'));

        // 2. Resolve by slug explicitly (no implicit binding, so a miss is a
        //    controlled 404 rather than a framework-shaped one).
        $page = StatusPage::query()->where('slug', $slug)->first();

        if ($page === null) {
            abort(404);
        }

        $hasPreviewToken = $this->hasValidPreviewToken($page, $request);

        // 3. Fail-closed gate: a missing page, or a private page without a valid
        //    preview token, is indistinguishable from a non-existent one.
        if (! $page->is_public && ! $hasPreviewToken) {
            abort(404);
        }

        // 4. Exactly one URL per language per page. The route segment accepts
        //    every supported language because a route cannot know which page it
        //    is about, so the page itself refuses the ONE prefix that duplicates
        //    its own unprefixed URL: without this a page published in Turkish
        //    would answer Turkish on both `/s/acme` and `/tr/s/acme`, and the
        //    duplicate would compete with the canonical it declares.
        //
        //    A 404 and never a redirect. This surface does not redirect between
        //    two languages of one document: Google's multi-regional guidance
        //    rules it out, and the language-offer banner exists precisely so the
        //    visitor makes that move themselves.
        if ($routeLocale === StatusPageLocale::canonical($page)) {
            abort(404);
        }

        // 5. The language this render answers in: the URL's when it carries one,
        //    the owner's `status_pages.locale` otherwise. Set on EVERY request
        //    that gets this far, INCLUDING when the value already equals the
        //    default, for the reason SetMarketingLocale documents at its own
        //    class docblock: the Octane translator singleton survives between
        //    requests, so a conditional "only when it is `tr`" would leave the
        //    worker in Turkish for whoever arrives next. `FlushLocaleState` in
        //    config/octane.php closes the same hole from the other side.
        //
        //    It sits AFTER the gate rather than beside the resolve above: a page
        //    whose existence this route refuses to confirm must not answer its
        //    404 in the language that page chose.
        //
        //    Everything downstream reads it implicitly. The assembler renders the
        //    banner label and each incident title under it, and the layout puts it
        //    in `<html lang>`.
        $locale = StatusPageLocale::render(is_string($routeLocale) ? $routeLocale : null, $page);

        app()->setLocale($locale);

        // 6. Where each language of this page lives, and the language this
        //    visitor's browser would rather read. Both are computed here rather
        //    than inside the cached payload below: the chrome is per (page,
        //    language) and the offer is per VISITOR, so caching either would
        //    serve one visitor's browser preference to everyone behind them.
        $chrome = new StatusPageChrome($page, $locale);
        $languageOffer = StatusPageLocale::offer($request, $locale);

        // 7. A token holder is a preview or a headless render, never a visitor,
        //    on a private page AND on a public one. It renders fresh, never
        //    touches the shared cache in either direction, and is marked
        //    no-store so an intermediary keying on neither the header nor the
        //    query cannot store this body under the public URL.
        if ($hasPreviewToken) {
            return $this->render($this->assembler->build($page), $chrome, $languageOffer)
                ->header('Cache-Control', 'no-store, private');
        }

        // 8. Public path: cache the plain-array read model (never the object)
        //    and rehydrate it for the view.
        //
        //    One entry per (page, LANGUAGE), because the payload is per-language
        //    by construction rather than by content: the assembler composes the
        //    banner label and every incident title under the locale set above,
        //    before `toArray()` runs. On one shared key the first URL to warm the
        //    cache decided the language of the next 60 seconds of traffic, which
        //    is a Turkish page publishing an English outage banner.
        //
        //    Every buster therefore fans out over the same language list; they
        //    all go through {@see StatusPageCache::forgetPage()}, which reads it
        //    from {@see self::cacheKeys()}.
        $data = Cache::remember(
            self::cacheKey($page->slug, $locale),
            60,
            fn (): array => $this->assembler->build($page)->toArray(),
        );

        return $this->render(StatusPageViewModel::fromArray($data), $chrome, $languageOffer);
    }

    /**
     * The cache key one page's read model occupies in one language.
     *
     * The only composer of this key in the application. Every buster reaches it
     * through {@see StatusPageCache::forgetPage()} rather than concatenating the
     * prefix again, so the shape can change here alone.
     */
    public static function cacheKey(string $slug, string $locale): string
    {
        return self::CACHE_KEY_PREFIX.$slug.':'.$locale;
    }

    /**
     * Every cache key one page's read model can occupy, one per language.
     *
     * The list is the union of the deployment default and the published
     * languages, in that order, which is exactly the set
     * {@see StatusPageLocale::render()} can answer with: the URL contributes a
     * published language, the page's own column is validated against the same
     * list by both the store and the update request, and the fallback is the
     * default. Reading it from config here rather than at each buster is what
     * makes adding a language a one-file change instead of a hunt.
     *
     * @return list<string>
     */
    public static function cacheKeys(string $slug): array
    {
        $locales = array_unique([
            (string) config('app.default_locale'),
            ...array_values((array) config('magic-starter.supported_locales', [])),
        ]);

        return array_values(array_map(
            static fn (string $locale): string => self::cacheKey($slug, $locale),
            $locales,
        ));
    }

    /**
     * Whether the request carries a preview token that constant-time matches the
     * page's token. Absent or mismatched tokens fail closed.
     */
    protected function hasValidPreviewToken(StatusPage $page, Request $request): bool
    {
        return static::previewTokenMatches($page->preview_token, $request);
    }

    /**
     * Whether the request carries a valid preview token for the page its
     * `{slug}` route parameter addresses.
     *
     * This is what the `resource-not-found` limiter routes a render onto its own
     * bucket by. The token is the only credential the renderer holds, and it is
     * what the relief keys on: a render fetches the app's own origin, but so can
     * real visitor traffic behind a proxy without TrustProxies configured, so an
     * address-based relief would drop slug-enumeration protection for everyone.
     *
     * The page is resolved ONLY once a token was actually supplied, so ordinary
     * visitor traffic costs no extra query.
     */
    public static function requestCarriesValidPreviewToken(Request $request): bool
    {
        if (static::suppliedPreviewTokens($request) === []) {
            return false;
        }

        $slug = $request->route('slug');

        if (! is_string($slug) || $slug === '') {
            return false;
        }

        return static::previewTokenMatches(
            StatusPage::query()->where('slug', $slug)->value('preview_token'),
            $request,
        );
    }

    /**
     * Constant-time compare a page's stored token against every transport the
     * request may carry one in.
     *
     * @param  mixed  $expected  The page's stored token, untyped at the model.
     */
    protected static function previewTokenMatches(mixed $expected, Request $request): bool
    {
        // Fail closed when the page has no token: otherwise an empty
        // `?preview_token=` would hash_equals('', '') and bypass the gate.
        if (! is_string($expected) || $expected === '') {
            return false;
        }

        foreach (static::suppliedPreviewTokens($request) as $provided) {
            if (hash_equals($expected, $provided)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every preview token the request supplies, header before query string.
     *
     * Empty and non-string values are dropped rather than compared, so a bare
     * `?preview_token=` or an empty header can never reach `hash_equals`.
     *
     * @return array<int, string>
     */
    protected static function suppliedPreviewTokens(Request $request): array
    {
        $supplied = [
            $request->header(self::PREVIEW_TOKEN_HEADER),
            $request->query('preview_token'),
        ];

        return array_values(array_filter(
            $supplied,
            fn (mixed $token): bool => is_string($token) && $token !== '',
        ));
    }

    /**
     * Render the status view from the read model, the slug, the page's language
     * chrome and this visitor's language offer.
     *
     * The chrome is SPREAD rather than handed over as one object, because the
     * partials that read it (`$canonicalUrl`, `$localeLinks`,
     * `$canonicalLocaleUrl`) dereference their variables unguarded, exactly as
     * `marketing/partials/seo-head.blade.php` documents: a render that forgets
     * one throws here rather than emitting a head with a hole in it, which is
     * the failure mode worth having on a page whose whole job is to be up when
     * nothing else is.
     *
     * @param  string|null  $languageOffer  The language this visitor's browser
     *                                      prefers, when it is not the one being rendered. Per visitor, so it
     *                                      travels beside the read model rather than inside it: the payload is
     *                                      cached and shared, and this value is neither.
     */
    protected function render(StatusPageViewModel $vm, StatusPageChrome $chrome, ?string $languageOffer): Response
    {
        return response()->view('status.show', [
            'vm' => $vm,
            'slug' => $chrome->page->slug,
            'languageOffer' => $languageOffer,
            ...$chrome->toArray(),
        ]);
    }
}
