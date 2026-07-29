<?php

namespace Tests\Feature\StatusPage;

use App\Enums\StatusPagePreviewStatus;
use App\Exceptions\StatusPagePreviewFailedException;
use App\Jobs\RenderStatusPagePreview;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\StatusPages\StatusPagePreviewRenderer;
use Closure;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Locks the render job's two decisions that fail SILENTLY when they are wrong.
 *
 * 1. Uniqueness cardinality. The job is unique PER PAGE and only until
 *    processing starts. Three tests here pin that as a triangle, because no
 *    single one of them is conclusive on its own:
 *      - two DIFFERENT pages both enqueue (a missing `uniqueId()` makes the
 *        lock global, so page B's render would be dropped because page A
 *        rendered, with nothing logged anywhere);
 *      - the SAME page twice while the first is still queued enqueues once,
 *        which is what proves the test above is not passing simply because no
 *        lock exists at all;
 *      - a dispatch DURING processing of the same page enqueues a follow-up,
 *        which is the difference between `ShouldBeUniqueUntilProcessing` and
 *        plain `ShouldBeUnique`. Plain uniqueness drops the LATER dispatch, so
 *        an operator who saves a page and then attaches three components gets
 *        an image of the state before those components, carrying a timestamp
 *        of now. That is exactly the drift this feature exists to remove.
 *    The uniqueness assertions run under `Queue::fake()` on purpose: under the
 *    `sync` connection the first job finishes and releases its lock before the
 *    second dispatch is even evaluated, so a sync-based test would pass no
 *    matter what the job declares.
 *
 * 2. Terminal state. `rendering` must not be reachable as a final state, or the
 *    editor strands on a skeleton forever. Two independent paths write the
 *    terminal state: `handle()` catches, records `failed` and rethrows, and
 *    `failed()` records it as well for the case where no catch block ever runs
 *    (a hard worker timeout kills the process mid-render). `$tries = 1` is what
 *    makes `failed()` fire on the first attempt rather than after a retry.
 */
class RenderStatusPagePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(StatusPage::PREVIEW_DISK);
    }

    /**
     * The success path, driven through the CONTAINER-bound renderer that
     * `Tests\TestCase` installs rather than through a local double.
     *
     * That makes this test the guard on the one mistake which would take the
     * whole suite down with it: a `new StatusPagePreviewRenderer` inside
     * `handle()` bypasses the double and spawns real Chromium under the sync
     * queue.
     */
    public function test_a_successful_render_completes_the_page_with_a_path_and_a_timestamp(): void
    {
        $page = $this->makePage('acme');

        RenderStatusPagePreview::dispatch($page);

        $fresh = $page->fresh();

        $this->assertSame(StatusPagePreviewStatus::Completed, $fresh->preview_render_status);
        $this->assertSame($page->previewImageStoragePath(), $fresh->preview_image_path);
        $this->assertNotNull($fresh->preview_rendered_at);
        $this->assertLessThanOrEqual(5, abs(now()->diffInSeconds($fresh->preview_rendered_at)));
        Storage::disk(StatusPage::PREVIEW_DISK)->assertExists($fresh->preview_image_path);
    }

    /**
     * `rendering` is written BEFORE the renderer runs, not after it returns.
     * The editor's skeleton state depends on the row saying so while the
     * browser is still working.
     */
    public function test_the_page_reads_rendering_while_the_renderer_is_running(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->bindRenderer();

        RenderStatusPagePreview::dispatch($page);

        $this->assertSame(
            [StatusPagePreviewStatus::Rendering],
            $renderer->statusesDuringRender,
            'The row must already read rendering while the render is in flight.'
        );
    }

    /**
     * A failing renderer leaves the terminal state and lets the exception out,
     * so the queue records a failed job instead of a silent no-op.
     */
    public function test_a_throwing_renderer_leaves_failed_and_rethrows(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->bindRenderer();
        $renderer->failure = StatusPagePreviewFailedException::captureFailed(
            $page,
            new RuntimeException('node exploded'),
        );

        $caught = null;

        try {
            RenderStatusPagePreview::dispatch($page);
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(StatusPagePreviewFailedException::class, $caught);

        $fresh = $page->fresh();
        $this->assertSame(StatusPagePreviewStatus::Failed, $fresh->preview_render_status);
        $this->assertNotSame(StatusPagePreviewStatus::Rendering, $fresh->preview_render_status);
        $this->assertSame([], Storage::disk(StatusPage::PREVIEW_DISK)->allFiles());
    }

    /**
     * The same failure path with `handle()` invoked directly, so the terminal
     * write is proven to come from the job's own catch block rather than from
     * the queue calling `failed()` afterwards.
     */
    public function test_handle_itself_records_the_failure_without_help_from_the_failed_hook(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->bindRenderer();
        $renderer->failure = new RuntimeException('node exploded');

        try {
            (new RenderStatusPagePreview($page, 'UTC'))->handle($renderer);
            $this->fail('A failing render must not return normally.');
        } catch (RuntimeException $e) {
            $this->assertSame('node exploded', $e->getMessage());
        }

        $this->assertSame(StatusPagePreviewStatus::Failed, $page->fresh()->preview_render_status);
    }

    /**
     * LOAD-BEARING NEGATIVE TEST: `failed()` on its own clears `rendering`.
     *
     * This is the path where no catch block runs at all: the worker's timeout
     * kills the process with SIGALRM while Chromium is still going, and the
     * only thing left is Laravel invoking `failed()` on a job rebuilt from its
     * payload. Without this hook the row keeps saying `rendering` forever and
     * the editor keeps showing a skeleton for a render that is already dead.
     */
    public function test_the_failed_hook_alone_clears_the_rendering_state(): void
    {
        $page = $this->makePage('acme');

        // The state a killed render leaves behind.
        $page->preview_render_status = StatusPagePreviewStatus::Rendering;
        $page->save();

        // Rebuilt from the payload exactly as the queue does it, so the write
        // is proven to work on a freshly restored model and not on the instance
        // that happened to set `rendering`.
        $job = unserialize(serialize(new RenderStatusPagePreview($page, 'UTC')));
        $job->failed(new RuntimeException('SIGALRM: the worker killed the render'));

        $fresh = $page->fresh();
        $this->assertSame(StatusPagePreviewStatus::Failed, $fresh->preview_render_status);
        $this->assertNotSame(
            StatusPagePreviewStatus::Rendering,
            $fresh->preview_render_status,
            'rendering must not survive as a terminal state.'
        );
    }

    /**
     * A manual `$job->fail()` passes no exception at all, so the hook has to
     * accept null rather than raising a TypeError on top of the failure.
     */
    public function test_the_failed_hook_accepts_a_missing_exception(): void
    {
        $page = $this->makePage('acme');

        (new RenderStatusPagePreview($page, 'UTC'))->failed(null);

        $this->assertSame(StatusPagePreviewStatus::Failed, $page->fresh()->preview_render_status);
    }

    /**
     * A failure keeps the previous artefact and its own timestamp: the editor
     * may still show it, but only under the stamp it was actually rendered at.
     */
    public function test_a_failed_render_keeps_the_previous_image_and_its_stamp(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->bindRenderer();

        RenderStatusPagePreview::dispatch($page);
        $completed = $page->fresh();

        $renderer->failure = new RuntimeException('node exploded');

        try {
            RenderStatusPagePreview::dispatch($page);
        } catch (Throwable) {
            // Asserted elsewhere; here only the retained columns matter.
        }

        $failed = $page->fresh();
        $this->assertSame(StatusPagePreviewStatus::Failed, $failed->preview_render_status);
        $this->assertSame($completed->preview_image_path, $failed->preview_image_path);
        $this->assertTrue($completed->preview_rendered_at->equalTo($failed->preview_rendered_at));
    }

    /**
     * LOAD-BEARING NEGATIVE TEST: the uniqueness lock is per page, not global.
     *
     * `ShouldBeUnique` without an explicit `uniqueId()` keys the lock on the
     * class name alone. Every existing unique job in this codebase is a
     * singleton scheduler, so none of them defines one; copying that shape here
     * would mean page B's render is dropped because page A rendered, silently.
     */
    public function test_two_dispatches_for_different_pages_both_enqueue(): void
    {
        Queue::fake();

        RenderStatusPagePreview::dispatch($this->makePage('acme'));
        RenderStatusPagePreview::dispatch($this->makePage('globex'));

        Queue::assertPushed(RenderStatusPagePreview::class, 2);
    }

    /**
     * The non-vacuity guard for the test above: there IS a lock, and for one
     * page it holds while that page's render is still waiting to be picked up.
     */
    public function test_a_second_dispatch_for_the_same_pending_page_is_dropped(): void
    {
        Queue::fake();
        $page = $this->makePage('acme');

        RenderStatusPagePreview::dispatch($page);
        RenderStatusPagePreview::dispatch($page);

        Queue::assertPushed(RenderStatusPagePreview::class, 1);
    }

    /**
     * The `ShouldBeUniqueUntilProcessing` pin, and the reason it is not plain
     * `ShouldBeUnique`: once a render starts, a change to the page must be able
     * to queue a follow-up render. Otherwise the stored PNG shows the state
     * from before the change while carrying a current timestamp.
     *
     * Driven through the real `sync` path rather than by releasing the lock by
     * hand, so the release point under test is the framework's own.
     */
    public function test_a_dispatch_during_processing_of_the_same_page_enqueues_a_follow_up(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->bindRenderer();

        // Stands in for the operator attaching a component while the render
        // triggered by their save is already running.
        $renderer->duringFirstRender = function (StatusPage $rendering): void {
            RenderStatusPagePreview::dispatch($rendering);
        };

        RenderStatusPagePreview::dispatch($page);

        $this->assertSame(
            2,
            $renderer->renders,
            'A change made while a render is in flight must queue a follow-up render.'
        );
    }

    /**
     * The timezone is resolved when the job is CONSTRUCTED, travels in the
     * payload, and is therefore reproduced identically by a retry. Reading
     * `config('app.timezone')` inside `handle()` instead would let a config
     * change between dispatch and run silently move every timestamp in the
     * image.
     */
    public function test_the_timezone_is_captured_at_dispatch_and_survives_a_retry(): void
    {
        $page = $this->makePage('acme');
        config(['app.timezone' => 'Europe/Istanbul']);

        $job = new RenderStatusPagePreview($page);

        $this->assertSame('Europe/Istanbul', $job->timezone);

        // A retry rebuilds the job from its serialized payload.
        config(['app.timezone' => 'America/New_York']);
        $this->assertSame('Europe/Istanbul', unserialize(serialize($job))->timezone);

        $renderer = $this->bindRenderer();
        dispatch($job);

        $this->assertSame(['Europe/Istanbul'], $renderer->timezones);
    }

    /**
     * An explicitly passed zone wins over the configured one, which is what
     * lets a retry of a payload rendered in another zone reproduce it.
     */
    public function test_an_explicit_timezone_overrides_the_configured_one(): void
    {
        $page = $this->makePage('acme');
        config(['app.timezone' => 'UTC']);

        $this->assertSame('Asia/Tokyo', (new RenderStatusPagePreview($page, 'Asia/Tokyo'))->timezone);
    }

    /**
     * A page deleted between dispatch and handle ends the job QUIETLY. The
     * alternative is a `ModelNotFoundException` that Horizon records as a
     * defect for a render nobody wants any more, and there is no row left to
     * write a status onto either way.
     */
    public function test_a_page_deleted_before_the_job_runs_ends_the_job_quietly(): void
    {
        $page = $this->makePage('acme');
        $renderer = $this->bindRenderer();
        $job = new RenderStatusPagePreview($page);

        // A mass delete: no model events, so nothing here depends on the
        // model's own cleanup hook running.
        StatusPage::query()->whereKey($page->getKey())->delete();

        dispatch($job);

        $this->assertSame(0, $renderer->renders, 'A deleted page must not be rendered.');
        $this->assertSame([], Storage::disk(StatusPage::PREVIEW_DISK)->allFiles());
    }

    /**
     * The queue contract the Horizon supervisor and the dev listener are sized
     * for. `$timeout` is deliberately NOT re-asserted here: it is pinned
     * against the whole timing chain by
     * Tests\Unit\PreviewQueueConfigTest::test_the_pinned_job_timeout_matches_the_render_job,
     * and a second copy of the number would only add a second place to edit.
     */
    public function test_the_job_declares_the_previews_queue_and_the_uniqueness_contract(): void
    {
        Queue::fake();
        $page = $this->makePage('acme');
        $job = new RenderStatusPagePreview($page);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame((string) $page->getKey(), $job->uniqueId());
        $this->assertSame(120, $job->uniqueFor);

        // One attempt is what makes `failed()` fire on the first failure. With
        // a retry, a hard timeout kill would leave the row at `rendering` with
        // nothing left to clean it up.
        $this->assertSame(1, $job->tries);

        RenderStatusPagePreview::dispatch($job->statusPage);

        Queue::assertPushedOn('previews', RenderStatusPagePreview::class);
    }

    /**
     * Install a renderer double as the container's renderer and hand it back.
     *
     * It overrides `render()` outright rather than the `capture()` seam: the
     * boundary under test here is the job's collaborator contract (a returned
     * storage key, or a thrown exception), and the renderer's own internals are
     * already locked by StatusPagePreviewRendererTest. Overriding the public
     * method also removes any path by which one of these tests could reach a
     * real browser.
     */
    protected function bindRenderer(): StatusPagePreviewRenderer
    {
        $double = new class extends StatusPagePreviewRenderer
        {
            /** How many times a render was asked for. */
            public int $renders = 0;

            /**
             * Timezones the job passed, in order.
             *
             * @var list<string>
             */
            public array $timezones = [];

            /**
             * The row's STORED `preview_render_status`, re-read from the
             * database once per render rather than taken from the model the job
             * handed over, so the assertion is about what a concurrent reader
             * (the editor) would see.
             *
             * @var list<StatusPagePreviewStatus|null>
             */
            public array $statusesDuringRender = [];

            /** Thrown instead of rendering, when set. */
            public ?Throwable $failure = null;

            /**
             * Runs during the FIRST render only, standing in for a concurrent
             * edit. Bounded to once so a re-entrant dispatch cannot recurse.
             *
             * @var (Closure(StatusPage): void)|null
             */
            public ?Closure $duringFirstRender = null;

            public function render(StatusPage $page, string $timezone): string
            {
                $this->renders++;
                $this->timezones[] = $timezone;
                $this->statusesDuringRender[] = StatusPage::query()
                    ->whereKey($page->getKey())
                    ->value('preview_render_status');

                if ($this->renders === 1 && $this->duringFirstRender !== null) {
                    ($this->duringFirstRender)($page);
                }

                if ($this->failure !== null) {
                    throw $this->failure;
                }

                $path = $page->previewImageStoragePath();
                Storage::disk(StatusPage::PREVIEW_DISK)->put($path, 'placeholder-png-bytes');

                return $path;
            }
        };

        $this->app->instance(StatusPagePreviewRenderer::class, $double);

        return $double;
    }

    /**
     * A status page owned by a fresh team.
     */
    protected function makePage(string $slug): StatusPage
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

        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'brand_color' => '#008560',
            'logo_text' => 'Uptizm',
            'description' => 'Live service status.',
            'is_public' => true,
            'subscriptions_enabled' => true,
        ]);
    }
}
