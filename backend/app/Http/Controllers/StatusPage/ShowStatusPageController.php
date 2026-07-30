<?php

namespace App\Http\Controllers\StatusPage;

use App\Enums\DomainMode;
use App\Http\ViewModels\StatusPageViewModel;
use App\Models\StatusPage;
use App\Services\StatusPages\StatusPageAssembler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the public status page for `GET /s/{slug}`.
 *
 * This is the security spine of the public surface. Two invariants are
 * enforced here and nowhere else:
 *
 *   - Fail-closed privacy: an unknown slug and a private page are BOTH answered
 *     with a 404 (never a 403, never a distinguishable body), so a visitor can
 *     neither confirm a private page exists nor enumerate slugs by status.
 *   - Cache isolation: only a genuinely public page is cached, and only its
 *     `toArray()` form (never the object, which fatals under the cache store's
 *     `serializable_classes => false`). ANY request carrying a valid preview
 *     token bypasses the cache entirely (no read, no write) and is answered
 *     `no-store, private`, so a private page can never be seeded into the
 *     shared, CDN-frontable cache, and a preview or a render of a PUBLIC page
 *     reports the page as it is now rather than up to 60 seconds of stale
 *     state stamped with the current time.
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

    public function __construct(
        protected StatusPageAssembler $assembler,
    ) {}

    /**
     * Resolve the page behind the privacy gate and render it, caching only the
     * public path's array payload.
     *
     * @throws NotFoundHttpException
     */
    public function __invoke(Request $request, string $slug): Response
    {
        // 1. Resolve by slug explicitly (no implicit binding, so a miss is a
        //    controlled 404 rather than a framework-shaped one).
        $page = StatusPage::query()->where('slug', $slug)->first();

        if ($page === null) {
            abort(404);
        }

        $hasPreviewToken = $this->hasValidPreviewToken($page, $request);

        // 2. Fail-closed gate: a missing page, or a private page without a valid
        //    preview token, is indistinguishable from a non-existent one.
        if (! $page->is_public && ! $hasPreviewToken) {
            abort(404);
        }

        // 3. A token holder is a preview or a headless render, never a visitor,
        //    on a private page AND on a public one. It renders fresh, never
        //    touches the shared cache in either direction, and is marked
        //    no-store so an intermediary keying on neither the header nor the
        //    query cannot store this body under the public URL.
        if ($hasPreviewToken) {
            return $this->render($this->assembler->build($page), $page)
                ->header('Cache-Control', 'no-store, private');
        }

        // 4. Public path: cache the plain-array read model (never the object)
        //    and rehydrate it for the view.
        $data = Cache::remember(
            "status-page:{$page->slug}",
            60,
            fn (): array => $this->assembler->build($page)->toArray(),
        );

        return $this->render(StatusPageViewModel::fromArray($data), $page);
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
     * Render the status view from the read model, the slug, and the page's own
     * canonical URL.
     */
    protected function render(StatusPageViewModel $vm, StatusPage $page): Response
    {
        return response()->view('status.show', [
            'vm' => $vm,
            'slug' => $page->slug,
            'canonicalUrl' => $this->canonicalUrl($page),
        ]);
    }

    /**
     * The one URL this page should be indexed and shared under.
     *
     * The same page answers on up to three hosts (`<app>/s/{slug}`,
     * `{slug}.<subdomain_host>`, and a `custom_domain`), so without a canonical
     * they compete as duplicates and a customer's page ranks against itself.
     *
     * Built from configuration, NEVER from the incoming request: `route()`
     * resolves an absolute URL against the request root, which would make the
     * canonical (and the OG url a crawler reads) differ per host and defeat the
     * point. `domain_mode` picks the form; a mode whose prerequisite is missing
     * falls back to the path form, which always resolves.
     */
    protected function canonicalUrl(StatusPage $page): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

        if ($page->domain_mode === DomainMode::Custom && is_string($page->custom_domain) && $page->custom_domain !== '') {
            return $scheme.'://'.$page->custom_domain.'/';
        }

        $subdomainHost = config('status_pages.subdomain_host');

        if ($page->domain_mode === DomainMode::Subdomain && is_string($subdomainHost) && $subdomainHost !== '') {
            return $scheme.'://'.$page->slug.'.'.$subdomainHost.'/';
        }

        return $base.'/s/'.$page->slug;
    }
}
