<?php

namespace App\Http\Controllers\StatusPage;

use App\Http\ViewModels\StatusPageViewModel;
use App\Models\StatusPage;
use App\Services\StatusPages\StatusPageAssembler;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
 *     `serializable_classes => false`). A preview request bypasses the cache
 *     entirely (no read, no write), so a private page can never be seeded into
 *     the shared, CDN-frontable cache.
 */
class ShowStatusPageController
{
    public function __construct(
        protected StatusPageAssembler $assembler,
    ) {}

    /**
     * Resolve the page behind the privacy gate and render it, caching only the
     * public path's array payload.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function __invoke(Request $request, string $slug): View
    {
        // 1. Resolve by slug explicitly (no implicit binding, so a miss is a
        //    controlled 404 rather than a framework-shaped one).
        $page = StatusPage::query()->where('slug', $slug)->first();

        // 2. Fail-closed gate: a missing page, or a private page without a valid
        //    preview token, is indistinguishable from a non-existent one.
        if ($page === null || (! $page->is_public && ! $this->hasValidPreviewToken($page, $request))) {
            abort(404);
        }

        // 3. A private page that passed the gate did so via a valid preview
        //    token, so it must render fresh and never touch the shared cache.
        if (! $page->is_public) {
            return $this->render($this->assembler->build($page), $page->slug);
        }

        // 4. Public path: cache the plain-array read model (never the object)
        //    and rehydrate it for the view.
        $data = Cache::remember(
            "status-page:{$page->slug}",
            60,
            fn (): array => $this->assembler->build($page)->toArray(),
        );

        return $this->render(StatusPageViewModel::fromArray($data), $page->slug);
    }

    /**
     * Whether the request carries a preview token that constant-time matches the
     * page's token. Absent or mismatched tokens fail closed.
     */
    protected function hasValidPreviewToken(StatusPage $page, Request $request): bool
    {
        $provided = $request->query('preview_token');
        $expected = $page->preview_token;

        // Fail closed when the page has no token: otherwise an empty
        // `?preview_token=` would hash_equals('', '') and bypass the gate.
        return is_string($expected)
            && $expected !== ''
            && is_string($provided)
            && hash_equals($expected, $provided);
    }

    /**
     * Render the status view from the read model and slug alone. The slug (not
     * the request URL) drives every URL the Blade generates via `route()`.
     */
    protected function render(StatusPageViewModel $vm, string $slug): View
    {
        return view('status.show', [
            'vm' => $vm,
            'slug' => $slug,
        ]);
    }
}
