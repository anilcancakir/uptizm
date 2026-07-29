<?php

namespace Tests;

use App\Services\StatusPages\StatusPagePreviewRenderer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A 1x1 transparent PNG, the placeholder image the preview-render double
     * writes instead of launching a browser. A real PNG rather than arbitrary
     * bytes, so anything downstream that inspects the stored file sees a valid
     * image.
     */
    protected const PLACEHOLDER_PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==';

    /**
     * Boot the application, then take headless Chromium out of the test suite.
     *
     * This is not a convenience. `phpunit.xml` sets `QUEUE_CONNECTION=sync`, and
     * several feature tests hit endpoints that dispatch a status-page preview
     * render, so the job runs INLINE inside those tests. Without this swap they
     * would each spawn a real browser: minutes of runtime, a hard dependency on
     * a provisioned Chromium in CI, and outbound navigation from the test
     * process.
     *
     * The double replaces only the `capture()` seam, so everything else the
     * renderer decides (the credential-free URL, the token header, the storage
     * key, the temp-file lifecycle, the failure classification) still runs for
     * real and stays under test. A test that needs to observe or fail a capture
     * binds its own subclass over this one.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $placeholder = (string) base64_decode(self::PLACEHOLDER_PNG_BASE64, true);

        $this->app->instance(
            StatusPagePreviewRenderer::class,
            new class($placeholder) extends StatusPagePreviewRenderer
            {
                public function __construct(protected string $placeholderPng) {}

                protected function capture(string $url, array $headers, string $timezone, string $temporaryPath): void
                {
                    file_put_contents($temporaryPath, $this->placeholderPng);
                }
            },
        );
    }
}
