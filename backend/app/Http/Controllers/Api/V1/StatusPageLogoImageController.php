<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StatusPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a status page's uploaded logo to a caller holding a valid signed URL.
 *
 * The same shape and the same trade as {@see StatusPagePreviewImageController},
 * for the same two reasons: the consumers fetch the bytes themselves and attach
 * no bearer token (a Flutter `Image.network()` in the editor, an `<img>` on the
 * public page), and only `api/*` carries CORS headers, which Flutter web needs to
 * read image bytes at all. The signature is therefore the whole authorisation,
 * and the page id is bound into it so one URL cannot be edited into another
 * page's.
 *
 * What a copied URL grants is narrower here than for the preview PNG: a brand
 * logo, not a rendered page carrying component names and incident history. It is
 * still a private page's asset, so the expiry stays short and bucketed.
 *
 * A 404 covers both "this page has no logo" and "the row names one but the bytes
 * are gone", the second being reachable whenever a file is removed out of band.
 * Neither may surface as a 500, and the editor already renders an initials
 * fallback for exactly this state.
 */
class StatusPageLogoImageController extends Controller
{
    /**
     * Route name, defined here so the resource that mints signed URLs and the
     * route table cannot drift apart.
     */
    public const ROUTE_NAME = 'api.v1.status-pages.logo-image';

    /**
     * How long a caller may cache the bytes, in seconds.
     *
     * Private, because the asset belongs to a page that may be private, and
     * short, because one page's logo is replaced in place at one path per
     * extension.
     */
    protected const CACHE_MAX_AGE_SECONDS = 300;

    /**
     * `Content-Type` per stored extension.
     *
     * Explicit rather than guessed from the file on the way out: the extension
     * was already decided from the CONTENT at upload time
     * ({@see StatusPageLogoController::store()}), and re-guessing here would let
     * a file replaced out of band choose its own type on a public surface.
     *
     * @var array<string, string>
     */
    protected const CONTENT_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    public function __invoke(Request $request, StatusPage $statusPage): StreamedResponse
    {
        $path = $statusPage->logo_path;

        abort_if(! is_string($path) || $path === '', HttpResponse::HTTP_NOT_FOUND);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // A path whose extension is not one this application stores can only be
        // a row written before the current convention or edited out of band, and
        // serving it would mean naming a `Content-Type` for bytes of unknown
        // kind on a public surface.
        abort_unless(
            array_key_exists($extension, self::CONTENT_TYPES),
            HttpResponse::HTTP_NOT_FOUND,
        );

        $disk = Storage::disk(StatusPage::LOGO_DISK);

        abort_unless($disk->exists($path), HttpResponse::HTTP_NOT_FOUND);

        return $disk->response($path, $this->downloadName($statusPage, $extension), [
            'Content-Type' => self::CONTENT_TYPES[$extension],
            'Cache-Control' => 'private, max-age='.self::CACHE_MAX_AGE_SECONDS,
        ]);
    }

    /**
     * Filename a save-image action lands on, so the asset stays identifiable
     * once it leaves the app.
     */
    protected function downloadName(StatusPage $statusPage, string $extension): string
    {
        $slug = $statusPage->slug;

        $base = is_string($slug) && $slug !== '' ? $slug : (string) $statusPage->getKey();

        return $base.'-logo.'.$extension;
    }
}
