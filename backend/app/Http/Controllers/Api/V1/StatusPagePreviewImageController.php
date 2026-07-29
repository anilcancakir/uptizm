<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StatusPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a status page's rendered preview PNG to a caller holding a valid
 * signed URL.
 *
 * THE SIGNATURE IS THE SOLE AUTHORISATION HERE, and that is a deliberate,
 * recorded trade rather than an oversight. This route sits outside
 * `auth:sanctum` because the consumer is a Flutter `Image.network()`, which
 * fetches the bytes itself and attaches no bearer token, so there is no actor to
 * compare a team against: the URL is a capability. It lives under `api/` for the
 * second half of the same requirement, since `config/cors.php` only sends CORS
 * headers for `api/*` and Flutter web reads image bytes through `fetch`.
 *
 * The confidentiality consequence, stated plainly: a copied URL yields the PNG
 * of a possibly-private status page until the signature expires, and that PNG
 * carries component names, incident history and 90-day uptime. Three things keep
 * the window small, and all three are load-bearing: the page id is bound into
 * the signature (so one URL cannot be edited into another page's), the expiry is
 * short and bucketed, and the status-page resource emits the URL from `show`
 * only so the capability is never multiplied across a list response or the log
 * payloads one ends up in.
 *
 * A 404 covers both "this page never rendered" and "the row says it did but the
 * file is gone", the second being reachable whenever a file is removed out of
 * band. Neither may surface as a 500.
 */
class StatusPagePreviewImageController extends Controller
{
    /**
     * Route name, defined here rather than only in `routes/api.php` because the
     * resource that MINTS the signed URL and the route that VALIDATES it must
     * agree exactly: a signature is computed over the generated URL, so a name
     * that drifts on one side yields a 403 on a legitimate link.
     */
    public const ROUTE_NAME = 'api.v1.status-pages.preview-image';

    /**
     * Client-cache lifetime, matching the URL's signing bucket.
     *
     * Safe to cache for the whole bucket precisely because the URL changes when
     * the image does (the render version is part of the signed payload), so a
     * cached response can never outlive the render it belongs to. `private`
     * keeps a shared cache out of it: this is one team's artefact.
     */
    protected const CACHE_MAX_AGE_SECONDS = 900;

    /**
     * Stream the stored PNG for the signed page, aborting with a 404 when the
     * page has no stored render or the stored file is gone.
     */
    public function __invoke(Request $request, StatusPage $statusPage): StreamedResponse
    {
        $path = $statusPage->preview_image_path;

        // 1. Never rendered. The signature can only have come from a resource
        //    read that saw a path, so this is the window between that read and
        //    a row that lost its path.
        abort_if(! is_string($path) || $path === '', HttpResponse::HTTP_NOT_FOUND);

        $disk = Storage::disk(StatusPage::PREVIEW_DISK);

        // 2. The row claims a render but the bytes are gone. Streaming a
        //    missing file would fail mid-response as a 500; a 404 is the honest
        //    answer and the client already renders a no-image state.
        abort_unless($disk->exists($path), HttpResponse::HTTP_NOT_FOUND);

        return $disk->response($path, $this->downloadName($statusPage), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age='.self::CACHE_MAX_AGE_SECONDS,
        ]);
    }

    /**
     * Filename a save-image action lands on, so the artefact is identifiable
     * once it leaves the app. Falls back to the key for a page with no slug.
     */
    protected function downloadName(StatusPage $statusPage): string
    {
        $slug = $statusPage->slug;

        return (is_string($slug) && $slug !== '' ? $slug : (string) $statusPage->getKey()).'-status-preview.png';
    }
}
