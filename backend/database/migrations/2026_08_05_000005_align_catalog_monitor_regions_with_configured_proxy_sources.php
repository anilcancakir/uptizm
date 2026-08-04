<?php

use App\Enums\MonitorRegion;
use App\Http\Controllers\Marketing\ShowBotController;
use App\Models\Service;
use App\Services\Proxy\ProxyPool;
use App\Services\Services\ServicePageAssembler;
use App\Support\Proxy\ProxyRegions;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring the catalog monitors that were seeded BEFORE the proxy pool existed onto
 * the region set this deployment can actually probe.
 *
 * {@see ServiceCatalogSeeder} is create-only per service: a re-seed touches an
 * existing service's monitor for its outbound identity and nothing else, on
 * purpose, so a re-seed cannot overwrite an operator's choices. That makes the
 * seeder's own region filter reach only catalog rows created after it landed.
 * Every catalog seeded earlier still carries all five {@see MonitorRegion} cases
 * on its monitors, which is what the seeder used to write.
 *
 * Two published numbers disagree while that is true, and they are on different
 * pages of the same site. {@see ShowBotController::probeRegionCount()} derives
 * its count from `config('proxy.sources')` filtered on a non-empty `location`,
 * because that page renders with no database connection available. The service
 * pages derive `[[service.region_count]]` from `max($monitor->regions)` off the
 * column instead. So `/bot` publishes the three regions a deployment sourced
 * while a service page publishes five, and the larger of the two is the one that
 * overstates our coverage. `ScheduleMonitorChecks` also keeps fanning checks out
 * to regions {@see ProxyPool::hasRegion()} was always going to refuse.
 *
 * The region set comes from {@see ProxyRegions}, the one function the seeder and
 * the `/bot` page also read, so a backfill cannot write a region set that
 * disagrees with what those two publish.
 *
 * ## Why it can decline to run
 *
 * Below {@see ServicePageAssembler::MIN_AGREEING_REGIONS} this migration does
 * NOTHING and says so by leaving the rows untouched. A deployment with one
 * configured region (or none, which is every test run and every fresh checkout)
 * would otherwise have its catalog monitors rewritten to a region set from which
 * an outage verdict is mathematically unreachable, and a migration that empties
 * the `regions` column stops the scheduler from probing the catalog at all. The
 * seeder THROWS in the same situation because it is building a catalog on
 * demand and a half-built one is worse than none; a migration runs unattended on
 * every deploy, where throwing would block the release over a value the release
 * does not change. Leaving the stale-but-working rows alone is the conservative
 * half of the same decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        $regions = ProxyRegions::sourced();

        if (count($regions) < ServicePageAssembler::MIN_AGREEING_REGIONS) {
            return;
        }

        // Catalog monitors, and ONLY catalog monitors: the `service_monitor` row
        // is what makes a monitor ours rather than a customer's, and a customer's
        // regions are their own choice. The subquery is the same predicate
        // {@see Service::monitors()} traverses, expressed without the model so
        // this migration keeps working if the relation is renamed.
        DB::table('monitors')
            ->whereIn('id', DB::table('service_monitor')->select('monitor_id'))
            ->update(['regions' => json_encode($regions)]);
    }

    public function down(): void
    {
        // Deliberately empty. The value this replaced was WRONG (a region set
        // with no configured exit, published as coverage on two public pages), so
        // there is nothing to restore and restoring it would be a regression
        // dressed as a rollback. Reversing the schema is a service; reversing a
        // correction is not.
    }
};
