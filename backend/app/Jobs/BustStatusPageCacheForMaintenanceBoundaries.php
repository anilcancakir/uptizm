<?php

namespace App\Jobs;

use App\Models\ScheduledMaintenance;
use App\Services\StatusPages\StatusPageCache;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Forgets the cached public read model of every status page whose maintenance
 * window has just opened or just closed.
 *
 * Every other bust in this system hangs off a WRITE: an incident opens, a
 * monitor flips, a page is edited, and {@see StatusPageCache} forgets the
 * affected keys at that boundary. A maintenance window has no such moment. Its
 * boundaries are two timestamps written once, possibly weeks earlier, so
 * nothing at all happens in the application when the clock crosses `starts_at`
 * or `ends_at` and the page keeps serving a read model built before the window
 * existed. This sweep is that missing write.
 *
 * Idempotent and deliberately over-eager: it looks back further than its own
 * tick interval so a skipped minute (a deploy, a scheduler restart) still busts
 * the boundary on the following run, and forgetting an already-forgotten key
 * costs nothing.
 *
 * Scheduled every minute in `routes/console.php`.
 */
class BustStatusPageCacheForMaintenanceBoundaries implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * How far back a boundary still counts as "just crossed".
     *
     * Wider than the one-minute schedule on purpose: a tick lost to a deploy or
     * a scheduler restart would otherwise leave the page stale until its next
     * unrelated write, and a duplicate forget is free.
     */
    private const BOUNDARY_LOOKBACK_SECONDS = 120;

    public function __construct()
    {
        $this->onQueue('scheduling');
    }

    public function handle(StatusPageCache $statusPageCache): void
    {
        // 1. Resolve the slugs of the pages whose window crossed a boundary in
        //    the lookback. The join is required because the cache key is built
        //    from the slug, which the maintenance row does not carry.
        $now = CarbonImmutable::now();
        $since = $now->subSeconds(self::BOUNDARY_LOOKBACK_SECONDS);

        $slugs = ScheduledMaintenance::query()
            ->join('status_pages', 'status_pages.id', '=', 'scheduled_maintenances.status_page_id')
            ->where(function (Builder $boundary) use ($since, $now): void {
                $boundary->whereBetween('scheduled_maintenances.starts_at', [$since, $now])
                    ->orWhereBetween('scheduled_maintenances.ends_at', [$since, $now]);
            })
            ->distinct()
            ->pluck('status_pages.slug');

        // 2. Forget each page through StatusPageCache rather than composing the
        //    key here: a page holds one entry per language, and the language list
        //    lives in exactly one place so a new language reaches this sweep
        //    without an edit. A window opening is not a per-language event, so
        //    every language of the page has to drop together.
        foreach ($slugs as $slug) {
            $statusPageCache->forgetPage((string) $slug);
        }
    }
}
