<?php

namespace Tests\Feature\StatusPage;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Exceptions\StatusPagePreviewFailedException;
use App\Http\Controllers\StatusPage\ShowStatusPageController;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\StatusPages\StatusPagePreviewRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Browsershot\Exceptions\UnsuccessfulResponse;
use Tests\TestCase;
use Throwable;

/**
 * Locks the headless preview renderer WITHOUT launching Chromium.
 *
 * Every test here drives an anonymous subclass that overrides the `capture()`
 * seam. The double is not a stub of the Browsershot chain: it asks the real
 * production factory to build the command it would have run and records it, so
 * the geometry, the timezone, the ready-marker wait and the header transport are
 * asserted against the ACTUAL configuration rather than against the double's
 * own arguments. Only the `node` process is skipped.
 *
 * The load-bearing assertions:
 *
 *   - the preview token travels in a header and never in the URL, proven end to
 *     end by replaying the recorded URL and headers against the real public
 *     controller: the URL alone is a 404, the URL plus the header is a 200;
 *   - a failed capture stores no PNG, leaves no temp file, and yields a typed
 *     exception whose message never contains the token;
 *   - the container hands every test a browserless double, which is what keeps
 *     `php artisan test` from spawning Chromium once Step 8 dispatches renders
 *     under the sync queue.
 */
class StatusPagePreviewRendererTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The ready marker the renderer waits on. Stated as a literal here on
     * purpose: it is a contract with `resources/views/status/layout.blade.php`,
     * so renaming it in the renderer alone must fail.
     */
    protected const READY_MARKER_SELECTOR = '[data-times-localized]';

    /**
     * Prefix of every temp file the renderer allocates, used to prove none
     * survives a failed render.
     */
    protected const TEMP_PREFIX = 'status-page-preview-';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(StatusPage::PREVIEW_DISK);
    }

    public function test_it_stores_the_png_at_the_deterministic_key(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();
        $renderer->bytes = 'first-render-bytes';

        $path = $renderer->render($page, 'UTC');

        $this->assertSame("status-page-previews/{$page->getKey()}.png", $path);
        $this->assertSame($page->previewImageStoragePath(), $path);
        Storage::disk(StatusPage::PREVIEW_DISK)->assertExists($path);
        $this->assertSame('first-render-bytes', Storage::disk(StatusPage::PREVIEW_DISK)->get($path));
    }

    public function test_a_second_render_overwrites_the_same_key(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();

        $renderer->bytes = 'first-render-bytes';
        $renderer->render($page, 'UTC');

        $renderer->bytes = 'second-render-bytes';
        $renderer->render($page, 'UTC');

        // One file per page, overwritten in place, so storage stays bounded.
        $this->assertCount(1, Storage::disk(StatusPage::PREVIEW_DISK)->allFiles());
        $this->assertSame(
            'second-render-bytes',
            Storage::disk(StatusPage::PREVIEW_DISK)->get($page->previewImageStoragePath()),
        );
    }

    public function test_the_token_travels_as_a_header_and_never_in_the_url(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();

        $renderer->render($page, 'UTC');

        $this->assertSame(route('status.show', ['slug' => 'acme']), $renderer->url);
        $this->assertStringNotContainsString((string) $page->preview_token, $renderer->url);
        $this->assertStringNotContainsString('preview_token', $renderer->url);

        // The header the public controller actually reads, so the two cannot
        // drift apart into a renderer that authorises against nothing.
        $this->assertSame(
            [ShowStatusPageController::PREVIEW_TOKEN_HEADER => $page->preview_token],
            $renderer->headers,
        );
        $this->assertSame(
            $renderer->headers,
            $renderer->command['options']['extraHTTPHeaders'],
        );
        $this->assertStringNotContainsString((string) $page->preview_token, $renderer->command['url']);
    }

    public function test_the_recorded_request_opens_a_private_page_while_the_url_alone_is_a_four_oh_four(): void
    {
        $page = $this->makePage('acme', isPublic: false);
        $renderer = $this->recordingRenderer();

        $renderer->render($page, 'UTC');

        // The URL carries no credential at all: on its own it is masked as 404.
        $this->get($renderer->url)->assertNotFound();

        // The recorded header is the whole authorisation, and it reaches the
        // real page, marker included.
        $response = $this->get($renderer->url, $renderer->headers);
        $response->assertOk();
        $response->assertSee('timesLocalized', escape: false);
    }

    public function test_it_renders_in_the_timezone_it_was_passed(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();

        $renderer->render($page, 'Europe/Istanbul');

        $this->assertSame('Europe/Istanbul', $renderer->timezone);
        $this->assertSame(
            ['TZ' => 'Europe/Istanbul'],
            $renderer->command['options']['env'],
        );
    }

    public function test_the_browsershot_chain_pins_the_geometry_the_marker_wait_and_the_launch_mode(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();

        $renderer->render($page, 'UTC');

        $options = $renderer->command['options'];

        $this->assertSame('png', $options['type']);
        // 816 = the page's own 768px content box (`max-w-3xl`) plus its 24px
        // padding either side (`sm:px-6`), i.e. the width at which `mx-auto` has
        // nothing left to centre. Rendering wider only adds dead white space:
        // at 1200 the artefact carried 216px of empty page on each side, which
        // dominated the pane once scaled into the editor's 380px column. Derive
        // this from layout.blade.php if that wrapper ever changes.
        $this->assertSame(816, $options['viewport']['width']);
        $this->assertSame(800, $options['viewport']['height']);
        // Scale 1, not 2: a 2400px artefact is unreadable at ~32% in the
        // editor's 380px column and a multi-MB download on mobile.
        $this->assertSame(1, $options['viewport']['deviceScaleFactor']);
        $this->assertTrue($options['fullPage']);
        $this->assertTrue($options['newHeadless']);
        $this->assertSame(30_000, $options['timeout']);

        // The marker wait gets its OWN short budget so a page that never
        // signals ready fails fast instead of burning the whole 30s.
        $this->assertSame(self::READY_MARKER_SELECTOR, $options['waitForSelector']);
        $this->assertLessThan($options['timeout'], $options['waitForSelectorOptions']['timeout']);
        $this->assertLessThanOrEqual(10_000, $options['waitForSelectorOptions']['timeout']);
        $this->assertGreaterThan(0, $options['waitForSelectorOptions']['timeout']);

        // No networkidle wait and no networkidle fallback.
        $this->assertArrayNotHasKey('networkIdleTimeout', $options);
        $this->assertArrayNotHasKey('waitUntil', $options);

        // Sandbox stays on: dropping it is a deployment decision, never the
        // default render path.
        $this->assertNotContains('--no-sandbox', $options['args']);

        // Defence in depth only. The load-bearing SSRF control is the markup
        // pin in StatusPageRenderTest, because Browsershot has no allowlist.
        $this->assertNotEmpty($options['blockDomains']);
    }

    public function test_a_throwing_capture_yields_the_typed_exception_and_stores_nothing(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();
        $renderer->failure = new RuntimeException('node exploded');

        try {
            $renderer->render($page, 'UTC');
            $this->fail('A failed capture must not return a path.');
        } catch (StatusPagePreviewFailedException $e) {
            $this->assertSame(StatusPagePreviewFailedException::STAGE_CAPTURE, $e->stage);
            $this->assertSame('node exploded', $e->getPrevious()?->getMessage());
        }

        $this->assertSame([], Storage::disk(StatusPage::PREVIEW_DISK)->allFiles());
    }

    public function test_a_missing_ready_marker_yields_the_typed_exception_and_stores_nothing(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();
        // Puppeteer's own wording when `waitForSelector` times out. The marker
        // is the render's success assertion: its absence means the captured
        // document was an error page, not the status page.
        $renderer->failure = new RuntimeException(
            'TimeoutError: Waiting for selector `'.self::READY_MARKER_SELECTOR.'` failed: Waiting failed: 8000ms exceeded',
        );

        try {
            $renderer->render($page, 'UTC');
            $this->fail('A render whose ready marker never appeared must not return a path.');
        } catch (StatusPagePreviewFailedException $e) {
            $this->assertSame(StatusPagePreviewFailedException::STAGE_READY_MARKER, $e->stage);
            $this->assertStringContainsString(self::READY_MARKER_SELECTOR, $e->getMessage());
        }

        $this->assertSame([], Storage::disk(StatusPage::PREVIEW_DISK)->allFiles());
    }

    public function test_an_error_status_response_yields_the_typed_exception_naming_that_stage(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();
        $renderer->failure = UnsuccessfulResponse::make(route('status.show', ['slug' => 'acme']), 429);

        try {
            $renderer->render($page, 'UTC');
            $this->fail('A throttled or missing page must not be stored as a preview.');
        } catch (StatusPagePreviewFailedException $e) {
            $this->assertSame(StatusPagePreviewFailedException::STAGE_HTTP_STATUS, $e->stage);
            // The status code reaches a Horizon failed-job payload through the
            // previous-exception chain.
            $this->assertStringContainsString('429', (string) $e);
        }

        $this->assertSame([], Storage::disk(StatusPage::PREVIEW_DISK)->allFiles());
    }

    public function test_a_capture_that_produces_no_image_data_yields_the_typed_exception(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();
        // A capture that reports success but writes nothing must not be stored:
        // a zero-byte PNG behind a `completed` row is worse than a failure.
        $renderer->bytes = '';

        try {
            $renderer->render($page, 'UTC');
            $this->fail('An empty capture must not be stored.');
        } catch (StatusPagePreviewFailedException $e) {
            $this->assertSame(StatusPagePreviewFailedException::STAGE_OUTPUT, $e->stage);
        }

        $this->assertSame([], Storage::disk(StatusPage::PREVIEW_DISK)->allFiles());
    }

    public function test_a_failed_render_leaves_no_temp_file_behind(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();
        $renderer->failure = new RuntimeException('node exploded');
        $before = $this->temporaryRenderFiles();

        try {
            $renderer->render($page, 'UTC');
        } catch (StatusPagePreviewFailedException) {
            // Asserted above; here only the temp-file lifecycle matters.
        }

        $this->assertNotSame('', $renderer->temporaryPath, 'The double never saw a temp path.');
        $this->assertFileDoesNotExist($renderer->temporaryPath);
        $this->assertSame([], $this->temporaryRenderFilesAddedSince($before));
    }

    public function test_a_successful_render_leaves_no_temp_file_behind(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();
        $before = $this->temporaryRenderFiles();

        $renderer->render($page, 'UTC');

        $this->assertFileDoesNotExist($renderer->temporaryPath);
        $this->assertSame([], $this->temporaryRenderFilesAddedSince($before));
    }

    public function test_the_failure_never_carries_the_preview_token(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->recordingRenderer();
        $renderer->failure = new RuntimeException('node exploded');

        try {
            $renderer->render($page, 'UTC');
            $this->fail('Expected the typed failure.');
        } catch (StatusPagePreviewFailedException $e) {
            // The token is generated once and never rotated, so a failed-job
            // payload that carried it would be indefinite read access.
            $this->assertStringNotContainsString((string) $page->preview_token, $e->getMessage());
            $this->assertStringNotContainsString((string) $page->preview_token, (string) $e);
            // It still says which page and which stage failed.
            $this->assertStringContainsString((string) $page->getKey(), $e->getMessage());
            $this->assertStringContainsString('acme', $e->getMessage());
            $this->assertSame((string) $page->getKey(), $e->statusPageKey);
        }
    }

    public function test_a_page_without_a_preview_token_fails_before_any_capture(): void
    {
        $page = $this->makePage('acme');
        // A mass update fires no model events, so it can defeat the `creating`
        // hook that normally guarantees a token. Rendering then would capture
        // the 404 mask of a private page.
        StatusPage::query()->whereKey($page->getKey())->update(['preview_token' => null]);
        $page->refresh();

        $renderer = $this->recordingRenderer();

        try {
            $renderer->render($page, 'UTC');
            $this->fail('A tokenless page must not be rendered.');
        } catch (StatusPagePreviewFailedException $e) {
            $this->assertSame(StatusPagePreviewFailedException::STAGE_PREVIEW_TOKEN, $e->stage);
        }

        $this->assertSame('', $renderer->url, 'capture() must not run for a tokenless page.');
        $this->assertSame([], Storage::disk(StatusPage::PREVIEW_DISK)->allFiles());
    }

    public function test_the_container_hands_every_test_a_browserless_double(): void
    {
        $resolved = app(StatusPagePreviewRenderer::class);

        // This is the assertion that keeps Chromium out of `php artisan test`:
        // five pre-existing tests hit endpoints that dispatch a render under
        // QUEUE_CONNECTION=sync, so the container must never hand them the real
        // renderer.
        $this->assertInstanceOf(StatusPagePreviewRenderer::class, $resolved);
        $this->assertNotSame(
            StatusPagePreviewRenderer::class,
            $resolved::class,
            'Tests\TestCase must swap the preview renderer for a browserless double.',
        );

        $page = $this->makePage('acme');
        $path = $resolved->render($page, 'UTC');

        Storage::disk(StatusPage::PREVIEW_DISK)->assertExists($path);
    }

    /**
     * A renderer that records what the real chain WOULD have run and then
     * writes placeholder bytes instead of shelling out to node.
     *
     * `$failure` makes the capture throw; `$bytes` is what a successful capture
     * writes to the temp path.
     */
    protected function recordingRenderer(): StatusPagePreviewRenderer
    {
        return new class extends StatusPagePreviewRenderer
        {
            public string $url = '';

            /** @var array<string, string> */
            public array $headers = [];

            public string $timezone = '';

            public string $temporaryPath = '';

            /** @var array<string, mixed> */
            public array $command = [];

            public string $bytes = 'placeholder-png-bytes';

            public ?Throwable $failure = null;

            protected function capture(string $url, array $headers, string $timezone, string $temporaryPath): void
            {
                $this->url = $url;
                $this->headers = $headers;
                $this->timezone = $timezone;
                $this->temporaryPath = $temporaryPath;

                // Built by the production factory, so every option assertion
                // is against the real chain rather than against this double.
                $this->command = $this->browsershotFor($url, $headers, $timezone)
                    ->createScreenshotCommand($temporaryPath);

                if ($this->failure !== null) {
                    throw $this->failure;
                }

                file_put_contents($temporaryPath, $this->bytes);
            }
        };
    }

    /**
     * Every temp file matching the renderer's prefix in the system temp
     * directory.
     *
     * @return list<string>
     */
    protected function temporaryRenderFiles(): array
    {
        return array_values((array) glob(sys_get_temp_dir().'/'.self::TEMP_PREFIX.'*'));
    }

    /**
     * Temp files the render just added, compared against a snapshot.
     *
     * Differential rather than absolute: the system temp directory is shared, so
     * a stale file from an earlier interrupted run would otherwise fail a
     * perfectly clean render.
     *
     * @param  list<string>  $before  Snapshot taken before the render.
     * @return list<string>
     */
    protected function temporaryRenderFilesAddedSince(array $before): array
    {
        return array_values(array_diff($this->temporaryRenderFiles(), $before));
    }

    /**
     * A status page owned by a fresh team, with one attached monitor so the
     * public route has real components to render.
     */
    protected function makePage(string $slug, bool $isPublic = true): StatusPage
    {
        $user = User::query()->create([
            'name' => 'Preview Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Preview Team',
        ]);

        $page = StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'brand_color' => '#008560',
            'logo_text' => 'Uptizm',
            'description' => 'Live service status.',
            'is_public' => $isPublic,
            'subscriptions_enabled' => true,
        ]);

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Checkout API',
            'type' => MonitorType::Http,
            'url' => 'https://internal.example.com/health',
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
            'last_status' => MonitorStatus::Up,
        ]);

        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        return $page;
    }
}
