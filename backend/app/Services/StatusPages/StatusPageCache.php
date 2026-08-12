<?php

namespace App\Services\StatusPages;

use App\Http\Controllers\StatusPage\ShowStatusPageController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Forgets the cached public-status-page read models kept under the plain
 * `status-page:{slug}:{locale}` keys by {@see ShowStatusPageController}.
 *
 * The cache is a 60-second read-through: without an explicit forget, an outage
 * that opens (or a recovery that resolves) an incident stays invisible on the
 * public page until the TTL lapses. This service is called at the incident
 * pivot-attach boundary so the page turns red the moment the incident lands.
 *
 * A page holds ONE ENTRY PER LANGUAGE, so every forget here fans out over the
 * languages {@see ShowStatusPageController::cacheKeys()} lists. Clearing only
 * one of them is worse than clearing none: the default-language URL goes red on
 * time while the other keeps publishing the pre-incident page, so the surface
 * looks healthy to exactly the visitors who are not reading it in English.
 *
 * Forget is plain-key and never tagged. The driver is `array` in the suite,
 * `database` by config default (so that is what an untouched developer checkout
 * runs), and `redis` in production; tags work on the first and the third and
 * throw on the second. A tags design would therefore pass CI, work in
 * production, and break for whoever did not set `CACHE_STORE` locally, which is
 * the worst place for that failure to surface. Resolving the affected slugs and
 * forgetting each key individually costs one round trip per (page, language).
 */
class StatusPageCache
{
    /**
     * Forget the cached read model of every status page that shows any of the
     * given monitors.
     *
     * A monitor can appear on many pages, so every containing page's key is
     * forgotten. Slugs come from the `status_page_monitors` pivot joined to
     * `status_pages` (the pivot alone carries no slug), de-duplicated so a
     * repeated slug forgets once.
     *
     * @param  array<int, string>  $monitorIds  Monitors whose containing pages to bust.
     */
    public function invalidateForMonitors(array $monitorIds): void
    {
        // 1. Nothing to resolve for an empty set; skip the query entirely.
        if ($monitorIds === []) {
            return;
        }

        // 2. Resolve the DISTINCT slugs of every page showing any of these
        //    monitors. The join is required because the pivot stores ids, not
        //    the slug the cache key is built from.
        $slugs = DB::table('status_page_monitors')
            ->join('status_pages', 'status_pages.id', '=', 'status_page_monitors.status_page_id')
            ->whereIn('status_page_monitors.monitor_id', $monitorIds)
            ->distinct()
            ->pluck('status_pages.slug');

        // 3. Plain-key forget per containing page, in every language it publishes
        //    in (driver-agnostic; no tags).
        foreach ($slugs as $slug) {
            $this->forgetPage((string) $slug);
        }
    }

    /**
     * Forget one page's cached read model in every language it is published in.
     *
     * The ONE fan-out in the application: the maintenance-boundary sweep and the
     * page-update endpoint call it too, so a language added to
     * `magic-starter.supported_locales` reaches every bust without a second edit
     * anywhere. A caller that looped the languages itself is a caller that would
     * be forgotten the next time that list changes.
     *
     * @param  string  $slug  The page whose entries to drop.
     */
    public function forgetPage(string $slug): void
    {
        foreach (ShowStatusPageController::cacheKeys($slug) as $key) {
            Cache::forget($key);
        }
    }
}
