<?php

namespace App\Services\StatusPages;

use App\Exceptions\StatusPagePreviewFailedException;
use App\Http\Controllers\StatusPage\ShowStatusPageController;
use App\Models\StatusPage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\Exceptions\UnsuccessfulResponse;
use Throwable;

/**
 * Renders a team's real public status page to a PNG with headless Chromium.
 *
 * The artefact this produces is the page AS IT RENDERED AT A STATED MOMENT, so
 * three properties matter more than the pixels:
 *
 *   - It must be the real page. Browsershot does not fail on a non-2xx response
 *     by default, and `/s/{slug}` is throttled per IP, so a 404 mask or a 429
 *     would otherwise be screenshotted and stored as a completed customer view.
 *     Two guards prevent that: the navigation status is checked
 *     (`preventUnsuccessfulResponse`), and the render waits for the ready marker
 *     that ONLY `resources/views/status/layout.blade.php` emits. There is no
 *     networkidle fallback: a capture that cannot prove it got the real page
 *     fails instead of storing something plausible.
 *   - It must be readable while private. The page's `preview_token` is the only
 *     credential, and it travels as a request HEADER
 *     ({@see ShowStatusPageController::PREVIEW_TOKEN_HEADER}), never in the URL,
 *     because a query token lands verbatim in every access log and this token is
 *     generated once and never rotated.
 *   - It must be deterministic. The timezone is passed in and applied to the
 *     browser process, so the timestamps inside the image belong to one declared
 *     zone rather than to whoever triggered the render.
 *
 * `capture()` is a protected seam so the class can be tested without launching
 * Chromium, and the class is container-bound so the whole renderer can be
 * swapped in the test environment (a protected seam alone would only cover this
 * class's own test, while five existing feature tests reach a render through the
 * sync queue). One class plus one test double, no interface.
 */
class StatusPagePreviewRenderer
{
    /**
     * Marker the status layout sets on `<html>` once the timestamp rewrite has
     * run AND webfonts have settled. A contract with that layout: renaming it
     * on either side breaks the render.
     */
    protected const READY_MARKER_SELECTOR = '[data-times-localized]';

    /**
     * Whole-render budget in seconds, bounding navigation, the marker wait and
     * the screenshot encode together.
     *
     * Sits below the render job's own timeout (40s), which sits below the
     * Horizon supervisor's (45s), so the failure surfaces here as a typed
     * exception rather than as a SIGALRM kill where no catch block runs.
     */
    protected const RENDER_TIMEOUT_SECONDS = 30;

    /**
     * Budget for the ready-marker wait alone, in milliseconds.
     *
     * Deliberately a small fraction of RENDER_TIMEOUT_SECONDS. The page is one
     * server-rendered document with one local stylesheet and self-hosted fonts,
     * so ready is a sub-second event; anything slower is a broken page, not a
     * slow one. Without its own budget puppeteer would default to 30s, exactly
     * the whole render budget, so an error-page capture would burn the entire
     * job instead of failing in 8 seconds with a specific stage.
     */
    protected const READY_MARKER_TIMEOUT_MS = 8_000;

    /**
     * Capture viewport. Height only seeds the first paint: `fullPage()` extends
     * the screenshot to the whole document.
     */
    protected const VIEWPORT_WIDTH = 1200;

    protected const VIEWPORT_HEIGHT = 800;

    /**
     * Prefix of the temp files a render allocates.
     */
    protected const TEMP_FILE_PREFIX = 'status-page-preview-';

    /**
     * Third-party hosts the render refuses to fetch.
     *
     * Read this as DEFENCE IN DEPTH ONLY, and do not read it as the SSRF
     * control. Browsershot exposes denylist primitives only (`blockDomains`,
     * `blockUrls`) and no allowlist, so "permit our own origin and nothing else"
     * is not expressible here; worse, `blockDomains` compares the hostname
     * EXACTLY, so it does not even cover subdomains of the entries below. A list
     * like this can never be complete.
     *
     * The load-bearing control is the markup assertion in
     * `StatusPageRenderTest::test_the_public_page_references_no_resource_outside_the_app_origin`,
     * which proves over our own rendered HTML and CSS that the page fetches
     * nothing external in the first place. The two cloud-metadata entries are
     * the exception worth having here: they are fixed addresses, not a moving
     * catalog of CDNs.
     *
     * @var list<string>
     */
    protected const BLOCKED_DOMAINS = [
        '169.254.169.254',
        'metadata.google.internal',
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'google-analytics.com',
        'www.google-analytics.com',
        'www.googletagmanager.com',
        'cdn.jsdelivr.net',
        'cdnjs.cloudflare.com',
        'unpkg.com',
    ];

    /**
     * Render the page and store its PNG at the page's deterministic key.
     *
     * @param  StatusPage  $page  The page to render; supplies both the public URL and the preview token.
     * @param  string  $timezone  Timezone identifier the browser process renders in, e.g. `UTC`.
     * @return string The storage key the PNG was written to, on {@see StatusPage::PREVIEW_DISK}.
     *
     * @throws StatusPagePreviewFailedException On every failure path. Nothing is
     *                                          swallowed and nothing partial is stored.
     */
    public function render(StatusPage $page, string $timezone): string
    {
        // 1. Fail before spending a browser on a page that cannot authorise its
        //    own render. The model's `creating` hook normally guarantees a
        //    token, but a mass update raises no model event, and rendering
        //    tokenless would capture the page's own 404 mask.
        $token = $page->preview_token;

        if (! is_string($token) || $token === '') {
            throw StatusPagePreviewFailedException::missingPreviewToken($page);
        }

        // 2. The URL is the plain public one. The credential travels in a
        //    header, so nothing secret can reach an access log or Telescope.
        $url = route('status.show', ['slug' => $page->slug]);
        $headers = [ShowStatusPageController::PREVIEW_TOKEN_HEADER => $token];

        $placeholderPath = tempnam(sys_get_temp_dir(), self::TEMP_FILE_PREFIX);

        if ($placeholderPath === false) {
            throw StatusPagePreviewFailedException::temporaryFileUnavailable($page);
        }

        // tempnam yields an extensionless file, and Browsershot infers the
        // screenshot type from the target extension, so capture into a
        // `.png`-suffixed sibling. Both paths are cleaned up below.
        $temporaryPath = $placeholderPath.'.png';

        try {
            $this->capture($url, $headers, $timezone, $temporaryPath);

            return $this->store($page, $temporaryPath);
        } catch (StatusPagePreviewFailedException $e) {
            // Already typed and already carries its stage.
            throw $e;
        } catch (Throwable $e) {
            throw $this->classify($page, $e);
        } finally {
            // 3. Neither temp artefact survives, on success or on failure, so a
            //    render can never leak an image of a private page into the
            //    system temp directory.
            $this->deleteTemporaryFiles($placeholderPath, $temporaryPath);
        }
    }

    /**
     * Run the headless capture, writing the PNG to `$temporaryPath`.
     *
     * The protected test seam: a subclass replaces the shell-out to node while
     * every other decision in this class (URL, header transport, storage key,
     * temp-file lifecycle, failure classification) still runs for real.
     *
     * @param  string  $url  The public status page URL, credential-free.
     * @param  array<string, string>  $headers  Request headers, carrying the preview token.
     * @param  string  $timezone  Timezone identifier for the browser process.
     * @param  string  $temporaryPath  Absolute `.png` path to write the capture to.
     */
    protected function capture(string $url, array $headers, string $timezone, string $temporaryPath): void
    {
        $this->browsershotFor($url, $headers, $timezone)->save($temporaryPath);
    }

    /**
     * Build the configured Browsershot instance for one capture.
     *
     * Kept separate from {@see self::capture()} so a test double can assert the
     * command this class WOULD have run (`createScreenshotCommand()`) without a
     * browser: the geometry, the timezone, the marker wait and the header
     * transport are then pinned against the real configuration rather than
     * against the double's own arguments.
     *
     * Launch mode is full Chrome (`newHeadless()`), not the default
     * `chrome-headless-shell`: the artefact is branded, and full Chrome has the
     * better font and CSS fidelity. `noSandbox()` is deliberately absent, so
     * dropping the sandbox stays a deployment decision rather than the default
     * render path.
     *
     * @param  string  $url  The public status page URL, credential-free.
     * @param  array<string, string>  $headers  Request headers, carrying the preview token.
     * @param  string  $timezone  Timezone identifier for the browser process.
     */
    protected function browsershotFor(string $url, array $headers, string $timezone): Browsershot
    {
        $browsershot = Browsershot::url($url)
            ->newHeadless()
            ->setExtraHttpHeaders($headers)
            // The browser process renders every timestamp in this zone, which is
            // what makes one stored image mean one declared time.
            //
            // Caveat worth knowing when debugging a wrong zone: Browsershot's
            // node wrapper spreads `process.env` AFTER these options, so a `TZ`
            // exported into the worker's own environment wins over this value.
            ->setEnvironmentOptions(['TZ' => $timezone])
            // The success assertion, with its own short budget (see the const).
            ->waitForSelector(self::READY_MARKER_SELECTOR, ['timeout' => self::READY_MARKER_TIMEOUT_MS])
            // A 404 mask or a 429 must fail the render, not be stored as a
            // customer view. Browsershot ignores the status code without this.
            ->preventUnsuccessfulResponse()
            ->windowSize(self::VIEWPORT_WIDTH, self::VIEWPORT_HEIGHT)
            ->deviceScaleFactor(1)
            ->fullPage()
            ->blockDomains(self::BLOCKED_DOMAINS)
            ->timeout(self::RENDER_TIMEOUT_SECONDS);

        return $this->applyBinaryPaths($browsershot);
    }

    /**
     * Move the captured PNG onto the pinned preview disk, at the page's own
     * deterministic key, overwriting the previous render.
     *
     * The disk comes from {@see StatusPage::PREVIEW_DISK} and the key from
     * {@see StatusPage::previewImageStoragePath()}: never
     * `config('filesystems.default')`, which a deploy can point at a public
     * disk, and never a second copy of the path convention.
     *
     * @return string The storage key written.
     *
     * @throws StatusPagePreviewFailedException When the capture produced nothing
     *                                          readable, or the write failed.
     */
    protected function store(StatusPage $page, string $temporaryPath): string
    {
        $bytes = is_file($temporaryPath) ? file_get_contents($temporaryPath) : false;

        if ($bytes === false || $bytes === '') {
            throw StatusPagePreviewFailedException::emptyOutput($page);
        }

        $path = $page->previewImageStoragePath();

        // The `local` disk is configured with `throw => false`, so a failed
        // write returns false. Ignoring that return would mark the page
        // `completed` with no image behind it.
        if (Storage::disk(StatusPage::PREVIEW_DISK)->put($path, $bytes) === false) {
            throw StatusPagePreviewFailedException::storeFailed($page, $path);
        }

        return $path;
    }

    /**
     * Turn a raw capture failure into the typed exception, naming the stage a
     * failed-job payload needs.
     *
     * The status-code case is structural (Browsershot raises its own typed
     * exception for it). The marker case is recognised from puppeteer's wording,
     * matched on the phrase rather than on the selector alone: a Browsershot
     * process dump embeds the serialized command, and that command CONTAINS the
     * selector, so matching the selector would label every failure a marker
     * failure. Misclassification only ever costs message quality: the render
     * fails either way, because the marker wait is what makes the process exit
     * non-zero and leaves no file to store.
     */
    protected function classify(StatusPage $page, Throwable $e): StatusPagePreviewFailedException
    {
        if ($e instanceof UnsuccessfulResponse) {
            return StatusPagePreviewFailedException::unsuccessfulResponse($page, $e);
        }

        if ($this->mentionsSelectorWait($e)) {
            return StatusPagePreviewFailedException::readyMarkerNeverAppeared(
                $page,
                self::READY_MARKER_SELECTOR,
                $e,
            );
        }

        return StatusPagePreviewFailedException::captureFailed($page, $e);
    }

    /**
     * Whether any message in the throwable chain is puppeteer reporting a
     * `waitForSelector` timeout. Matched case-insensitively because the wording
     * changed case across puppeteer versions.
     */
    protected function mentionsSelectorWait(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (stripos($current->getMessage(), 'waiting for selector') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Point Browsershot at explicit node / npm binaries when configured.
     *
     * A queue worker started by a process manager often has a narrower PATH
     * than the web process, which is exactly what these config values exist
     * for. Both are null by default, leaving Browsershot's own PATH lookup in
     * place.
     */
    protected function applyBinaryPaths(Browsershot $browsershot): Browsershot
    {
        $nodeBinary = config('services.browsershot.node_binary');

        if (is_string($nodeBinary) && $nodeBinary !== '') {
            $browsershot->setNodeBinary($nodeBinary);
        }

        $npmBinary = config('services.browsershot.npm_binary');

        if (is_string($npmBinary) && $npmBinary !== '') {
            $browsershot->setNpmBinary($npmBinary);
        }

        return $browsershot;
    }

    /**
     * Remove both temp artefacts a render can create: the extensionless
     * `tempnam` placeholder and the `.png` capture target.
     *
     * Deleted through the framework's filesystem rather than a bare `unlink`
     * because this runs in a `finally`: Laravel's error handler turns an
     * `unlink` warning into an ErrorException, and an exception raised here
     * would REPLACE the render failure that is on its way out, hiding the actual
     * cause behind a temp-file detail.
     */
    protected function deleteTemporaryFiles(string $placeholderPath, string $temporaryPath): void
    {
        foreach ([$placeholderPath, $temporaryPath] as $path) {
            if (is_file($path)) {
                File::delete($path);
            }
        }
    }
}
