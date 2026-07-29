<?php

namespace App\Jobs;

use App\Enums\StatusPagePreviewStatus;
use App\Models\StatusPage;
use App\Services\StatusPages\StatusPagePreviewRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Renders one status page's public view to a stored PNG and records the outcome
 * on the row the editor reads.
 *
 * Two properties of this job matter more than what it does, because both fail
 * SILENTLY when they are wrong.
 *
 * UNIQUENESS IS PER PAGE, AND ONLY UNTIL PROCESSING STARTS. The three existing
 * unique jobs in this codebase are singleton schedulers, so none of them defines
 * a `uniqueId()`; without one the lock is keyed on the class name alone and page
 * B's render would be dropped because page A rendered. And plain
 * `ShouldBeUnique` drops the LATER dispatch, which is the exact drift this
 * feature exists to remove: an operator who saves a page and then attaches three
 * components produces four dispatches inside the uniqueness window, so the one
 * surviving render would show the state from BEFORE those components while
 * carrying a timestamp of now. `ShouldBeUniqueUntilProcessing` releases the lock
 * when processing begins, so an edit made during a render queues a follow-up.
 *
 * `rendering` IS NEVER A TERMINAL STATE. The editor shows a skeleton for it, so
 * a row stuck there is a pane that never resolves. Two independent paths write a
 * terminal state: `handle()` catches, records `failed` and rethrows, and
 * {@see self::failed()} records it for the case where no catch block runs at all
 * (the worker's timeout kills the process with SIGALRM while Chromium is still
 * working). `$tries = 1` is what makes that hook fire on the FIRST failure:
 * Laravel only calls it on the attempt that exhausts the tries, so a retry would
 * leave the first hard kill with nothing to clean up after it. A render is cheap
 * to re-trigger and the operator has a retry action, so one attempt is the
 * cheaper side of that trade.
 */
class RenderStatusPagePreview implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Queue served by its own Horizon supervisor, because one render holds a
     * Chromium process and the shared supervisor's memory ceiling and process
     * count are both sized for cheap work.
     */
    protected const QUEUE = 'previews';

    /**
     * One attempt, so the failure hook fires on the first failure. See the
     * class docblock: this is what keeps `rendering` from stranding.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Whole-job budget in seconds. Sits above the renderer's own 30s capture
     * timeout and below the Horizon supervisor's 45s, so a slow render surfaces
     * as this job's own failure rather than as a worker kill.
     *
     * The whole chain (30 < 40 < 45 < retry_after 90) is asserted by
     * Tests\Unit\PreviewQueueConfigTest, which reflects this property. Changing
     * the number here without re-deriving the chain fails that test.
     *
     * @var int
     */
    public $timeout = 40;

    /**
     * Seconds the per-page lock is held for if nothing releases it earlier.
     *
     * A ceiling, not the normal lifetime: processing releases the lock, so this
     * only bounds the case where a dispatched job never gets picked up.
     *
     * @var int
     */
    public $uniqueFor = 120;

    /**
     * A page deleted between dispatch and handle ends the job quietly.
     *
     * `SerializesModels` re-fetches the row when the worker unserializes the
     * payload, and the default behaviour is to fail the job with a
     * `ModelNotFoundException`. That would record a defect for a render nobody
     * wants any more, and there is no row left to write a status onto either
     * way, so deleting is the honest outcome.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * Timezone the browser process renders every timestamp in.
     *
     * Resolved once, in the constructor, which runs at DISPATCH time, and
     * carried in the serialized payload from there. A retry therefore reproduces
     * the zone the dispatch was made under, and a config change between dispatch
     * and run cannot silently move every timestamp inside the image.
     */
    public readonly string $timezone;

    /**
     * @param  StatusPage  $statusPage  The page to render.
     * @param  string|null  $timezone  Overrides the configured application timezone.
     *                                 Callers normally omit it; a re-dispatch that
     *                                 must reproduce an earlier render passes it.
     */
    public function __construct(
        public StatusPage $statusPage,
        ?string $timezone = null,
    ) {
        $this->timezone = $timezone ?? (string) config('app.timezone');

        $this->onQueue(self::QUEUE);
    }

    /**
     * Lock key: one in-flight render per page.
     *
     * Explicit on purpose. The default is an empty id, which makes the lock
     * global across every status page in every team.
     */
    public function uniqueId(): string
    {
        return (string) $this->statusPage->getKey();
    }

    /**
     * Render the page and record the outcome.
     *
     * The renderer arrives through the container rather than through `new`: the
     * test environment binds a browserless double over it, and several feature
     * tests reach this job through the sync queue, so constructing the real one
     * here would spawn Chromium inside `php artisan test`.
     */
    public function handle(StatusPagePreviewRenderer $renderer): void
    {
        // 1. Publish the in-flight state before the browser starts, so the
        //    editor can show its skeleton for the whole duration rather than
        //    only after the render already finished.
        $this->statusPage->preview_render_status = StatusPagePreviewStatus::Rendering;
        $this->statusPage->save();

        try {
            $path = $renderer->render($this->statusPage, $this->timezone);
        } catch (Throwable $e) {
            // 2. Record the terminal state, then let the failure out: the queue
            //    has to see it to record a failed job, and swallowing it here
            //    would leave a page looking merely un-rendered rather than
            //    broken. The previous image and its stamp are deliberately kept,
            //    so a stale artefact can still be shown under its own visibly
            //    old timestamp.
            $this->markFailed();

            throw $e;
        }

        // 3. Only now is there an artefact to point at. The path and the stamp
        //    are written together with the status so no reader can see
        //    `completed` next to a stale path.
        $this->statusPage->preview_image_path = $path;
        $this->statusPage->preview_rendered_at = now();
        $this->statusPage->preview_render_status = StatusPagePreviewStatus::Completed;
        $this->statusPage->save();
    }

    /**
     * Last-resort terminal write, for the failures no catch block ever sees.
     *
     * Laravel rebuilds the job from its payload to call this, so `$statusPage`
     * here is freshly loaded and independent of whatever the killed attempt had
     * in memory.
     *
     * @param  Throwable|null  $exception  Null when the job was failed manually.
     */
    public function failed(?Throwable $exception): void
    {
        $this->markFailed();
    }

    /**
     * Write the terminal failure state, leaving any previous render's path and
     * timestamp intact.
     */
    protected function markFailed(): void
    {
        $this->statusPage->preview_render_status = StatusPagePreviewStatus::Failed;
        $this->statusPage->save();
    }
}
