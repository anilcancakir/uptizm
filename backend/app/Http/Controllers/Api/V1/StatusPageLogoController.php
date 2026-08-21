<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStatusPageLogoRequest;
use App\Http\Resources\StatusPageResource;
use App\Jobs\RenderStatusPagePreview;
use App\Models\StatusPage;
use App\Services\StatusPages\StatusPageCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Uploads and removes a status page's brand logo.
 *
 * Separate from {@see StatusPageController} for the same reason
 * {@see StatusPagePreviewImageController} is: this is the only write on this
 * resource that takes a multipart body and touches a disk, and `logo_path` is
 * the only column on the page that a client must NOT be able to set directly.
 * That is why it is absent from `StoreStatusPageRequest` and
 * `UpdateStatusPageRequest`: the value is a path this application later reads
 * back off a disk to serve bytes, so a client-supplied string would be a
 * traversal away from serving any file the process can open. Here the path is
 * derived from the page's own key and a content-guessed extension.
 *
 * Both writes carry the same two side effects as an ordinary page edit, and both
 * are load-bearing rather than defensive: the public read model is cached and now
 * carries the logo, and the preview PNG renders the brand mark, so a new logo
 * that skipped either one would be live in the editor and stale everywhere a
 * visitor looks.
 */
class StatusPageLogoController extends Controller
{
    public function __construct(
        protected StatusPageCache $statusPageCache,
    ) {}

    /**
     * Replace the page's logo with the uploaded image.
     */
    public function store(
        UpdateStatusPageLogoRequest $request,
        StatusPage $statusPage,
    ): StatusPageResource {
        $this->authorizeTeam($request, $statusPage);

        $file = $request->file('logo');

        // 1. The extension comes from the file's CONTENT, never from the name
        //    the client sent. A `.png` filename wrapping a JPEG would otherwise
        //    be stored as a PNG and served with the wrong `Content-Type`, and a
        //    filename is the half of an upload an attacker fully controls.
        $extension = strtolower((string) $file->extension());

        abort_unless(
            in_array($extension, StatusPage::LOGO_EXTENSIONS, true),
            HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
        );

        $disk = Storage::disk(StatusPage::LOGO_DISK);
        $previousPath = $statusPage->logo_path;
        $path = $statusPage->logoStoragePath($extension);

        $disk->putFileAs(StatusPage::LOGO_DIRECTORY, $file, basename($path));

        // 2. One page owns one logo, but the extension is part of its name, so
        //    replacing a PNG with a WebP writes a SECOND file rather than
        //    overwriting the first. Without this the old bytes would outlive
        //    every reference to them, on the disk of a page whose whole design
        //    is that private content stays unreachable.
        if (is_string($previousPath) && $previousPath !== '' && $previousPath !== $path) {
            $disk->delete($previousPath);
        }

        $statusPage->update(['logo_path' => $path]);

        return $this->respondWithFreshPage($statusPage);
    }

    /**
     * Remove the page's logo, falling the brand mark back to its initials.
     */
    public function destroy(Request $request, StatusPage $statusPage): StatusPageResource
    {
        $this->authorizeTeam($request, $statusPage);

        $path = $statusPage->logo_path;

        // Idempotent: a page with no logo is already in the requested state, so
        // this answers with the page rather than a 404 a client would have to
        // special-case after a double tap.
        if (is_string($path) && $path !== '') {
            Storage::disk(StatusPage::LOGO_DISK)->delete($path);
        }

        $statusPage->update(['logo_path' => null]);

        return $this->respondWithFreshPage($statusPage);
    }

    /**
     * Drop the cached public read model, re-render the preview, and return the
     * page as the editor will now read it.
     */
    protected function respondWithFreshPage(StatusPage $statusPage): StatusPageResource
    {
        $this->statusPageCache->forgetPage($statusPage->slug);

        RenderStatusPagePreview::dispatch($statusPage)->afterCommit();

        return StatusPageResource::make($statusPage->refresh()->load('monitors'));
    }

    /**
     * Mask another team's page as absent rather than forbidden, mirroring
     * {@see StatusPageController::authorizeTeam()}.
     */
    protected function authorizeTeam(Request $request, StatusPage $statusPage): void
    {
        abort_if(
            $statusPage->team_id !== $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }
}
