<?php

namespace App\Exceptions;

use App\Models\StatusPage;
use RuntimeException;
use Throwable;

/**
 * A headless status-page preview render that did not produce a stored PNG.
 *
 * This is queue-side, not HTTP-side: it is read from a Horizon failed-job
 * payload, so the message has to be self-sufficient. It names WHICH page (key
 * plus slug) and WHICH stage failed, because the underlying Browsershot error
 * is either a Symfony process dump or a puppeteer timeout, neither of which
 * says anything about the artefact being rendered.
 *
 * What it deliberately never carries is the page's `preview_token`. That token
 * is generated once and never rotated, so a single failed-job payload holding
 * it would be indefinite read access to a private page. The token reaches the
 * browser as a request HEADER for the same reason, and every message here is
 * built from the named constructors below so no call site can slip it in.
 *
 * The stages are plain constants rather than an enum: they are debug metadata
 * on one exception, not domain state a client or a column ever sees. That role
 * belongs to the StatusPagePreviewStatus enum, which is deliberately NOT
 * referenced here, since a docblock reference would make Pint import a class
 * this file never uses in code.
 */
class StatusPagePreviewFailedException extends RuntimeException
{
    /**
     * The page had no stored preview token, so it could not have authorised its
     * own render. Detected before any browser is launched.
     */
    public const STAGE_PREVIEW_TOKEN = 'preview-token';

    /**
     * No temp file could be allocated for the screenshot.
     */
    public const STAGE_TEMP_FILE = 'temp-file';

    /**
     * Browsershot failed for a reason none of the stages below explains.
     */
    public const STAGE_CAPTURE = 'capture';

    /**
     * The ready marker never appeared, so what the browser had on screen was
     * not the real status page.
     */
    public const STAGE_READY_MARKER = 'ready-marker';

    /**
     * The page URL answered with a 4xx or 5xx status.
     */
    public const STAGE_HTTP_STATUS = 'http-status';

    /**
     * The capture reported success but left no readable image behind.
     */
    public const STAGE_OUTPUT = 'output';

    /**
     * The image was captured but could not be written to the preview disk.
     */
    public const STAGE_STORE = 'store';

    /**
     * @param  string  $stage  One of the `STAGE_*` constants.
     * @param  string  $statusPageKey  Primary key of the page being rendered.
     * @param  string  $slug  Public slug of the page being rendered.
     */
    final protected function __construct(
        public readonly string $stage,
        public readonly string $statusPageKey,
        public readonly string $slug,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The page carries no preview token, so a private page would render as its
     * own 404 mask. Fails before the browser starts.
     */
    public static function missingPreviewToken(StatusPage $page): static
    {
        return static::make(
            $page,
            self::STAGE_PREVIEW_TOKEN,
            'the page has no stored preview token, so the render could not authorise itself.',
        );
    }

    /**
     * No temp file could be allocated to receive the screenshot.
     */
    public static function temporaryFileUnavailable(StatusPage $page): static
    {
        return static::make(
            $page,
            self::STAGE_TEMP_FILE,
            'no temporary file could be allocated for the screenshot.',
        );
    }

    /**
     * Browsershot failed for an unclassified reason. The cause is preserved as
     * the previous exception, so its process output reaches the failed-job
     * payload through `(string) $exception`.
     */
    public static function captureFailed(StatusPage $page, Throwable $previous): static
    {
        return static::make(
            $page,
            self::STAGE_CAPTURE,
            'the headless capture failed.',
            $previous,
        );
    }

    /**
     * The ready marker never appeared within its own wait budget. This is the
     * render's success assertion, not merely a wait: only the status layout
     * emits the marker, so its absence means the browser captured an error or
     * throttled response, or the page's JavaScript failed.
     *
     * @param  string  $selector  The marker selector that was waited on.
     */
    public static function readyMarkerNeverAppeared(StatusPage $page, string $selector, Throwable $previous): static
    {
        return static::make(
            $page,
            self::STAGE_READY_MARKER,
            sprintf(
                'the ready marker `%s` never appeared, so the captured document was not the status page '
                .'(an error or throttled response, or a JavaScript failure).',
                $selector,
            ),
            $previous,
        );
    }

    /**
     * The page URL answered 4xx or 5xx. The status code itself travels in the
     * previous exception's message.
     */
    public static function unsuccessfulResponse(StatusPage $page, Throwable $previous): static
    {
        return static::make(
            $page,
            self::STAGE_HTTP_STATUS,
            'the page URL answered with an error status instead of the status page.',
            $previous,
        );
    }

    /**
     * The capture claimed success but wrote nothing readable, so there is no
     * artefact to store.
     */
    public static function emptyOutput(StatusPage $page): static
    {
        return static::make(
            $page,
            self::STAGE_OUTPUT,
            'the capture produced no readable image data.',
        );
    }

    /**
     * The preview disk refused the write. The `local` disk is configured with
     * `throw => false`, so a failed write returns false instead of raising, and
     * silently returning here would mark a page `completed` with no PNG.
     *
     * @param  string  $path  The storage key the write targeted.
     */
    public static function storeFailed(StatusPage $page, string $path): static
    {
        return static::make(
            $page,
            self::STAGE_STORE,
            sprintf('the rendered image could not be written to `%s` on the `%s` disk.', $path, StatusPage::PREVIEW_DISK),
        );
    }

    /**
     * Build the exception with the one message shape every stage shares, so the
     * page context can never be forgotten and the token can never be added.
     *
     * @param  string  $stage  One of the `STAGE_*` constants.
     * @param  string  $detail  The stage-specific sentence, lower-cased.
     */
    protected static function make(StatusPage $page, string $stage, string $detail, ?Throwable $previous = null): static
    {
        $key = (string) $page->getKey();
        $slug = (string) $page->slug;

        return new static(
            $stage,
            $key,
            $slug,
            sprintf(
                'Status page preview render failed at stage [%s] for page %s (%s): %s',
                $stage,
                $key,
                $slug,
                $detail,
            ),
            $previous,
        );
    }
}
