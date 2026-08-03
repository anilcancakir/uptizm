<?php

namespace Tests;

use App\Models\StatusPage;
use App\Services\StatusPages\StatusPagePreviewRenderer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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

        // Keep rendered previews off the real disk. Four write endpoints now
        // dispatch a render, the suite runs the queue synchronously, and the
        // double below writes a real file, so without this every full run left
        // placeholder PNGs behind in storage/app/private and they accumulated
        // across runs. A test that wants to observe the stored file re-fakes or
        // asserts on this same disk.
        Storage::fake(StatusPage::PREVIEW_DISK);

        // Keep monitor-content archive writes off the real disk for the same
        // reason as the preview disk above: a separate disk is not redirected
        // by faking `local`, so without this every archive-writing test would
        // leave real `.gz` blobs accumulating under storage/app/private.
        Storage::fake(config('content-archive.disk'));

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

    /**
     * Reset Livewire's process-global state between tests.
     *
     * Rendering any Livewire component, which is what every Filament panel page
     * is, sets `SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest`. That
     * flag is STATIC, so it outlives PHPUnit's per-test application rebuild: once
     * one test renders a panel page, Livewire injects its `<style>` and `<script>`
     * block into every later response in the same process, including responses
     * from route files that never involve Livewire at all.
     *
     * That is not cosmetic. It broke two pre-existing suites that assert on exact
     * HTML, and both failed ONLY in a full run while passing in isolation, which
     * is the hardest shape of failure to attribute:
     *
     *   - `Marketing\ContactFormTest::test_the_honeypot_is_reachable_and_labelled_rather_than_display_none`,
     *     because the injected CSS contains `display: none`.
     *   - `StatusPage\SubscribeTest::test_a_second_subscribe_with_the_same_email_is_deduped`.
     *
     * Flushed here rather than in the panel suites themselves because the panel
     * resource tests are numerous and each new one would otherwise have to
     * remember, and a forgotten reset shows up as a failure in somebody else's
     * unrelated test file. Uses Livewire's own documented flush seam, so no
     * assertion anywhere had to be weakened to accommodate the panel.
     */
    protected function tearDown(): void
    {
        Livewire::flushState();

        parent::tearDown();
    }
}
