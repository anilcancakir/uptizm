<?php

namespace App\Services\StatusPages;

use App\Http\Controllers\StatusPage\ShowStatusPageController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Forgets the cached public-status-page read models kept under the plain
 * `status-page:{slug}` key by {@see ShowStatusPageController}.
 *
 * The cache is a 60-second read-through: without an explicit forget, an outage
 * that opens (or a recovery that resolves) an incident stays invisible on the
 * public page until the TTL lapses. This service is called at the incident
 * pivot-attach boundary so the page turns red the moment the incident lands.
 *
 * Forget is plain-key and driver-agnostic on purpose: cache tags are
 * unsupported on the database/file drivers this app runs, so invalidation
 * resolves the affected slugs and forgets each key individually.
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

        // 3. Plain-key forget per containing page (driver-agnostic; no tags).
        foreach ($slugs as $slug) {
            Cache::forget("status-page:{$slug}");
        }
    }
}
