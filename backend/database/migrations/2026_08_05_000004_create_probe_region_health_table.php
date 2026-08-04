<?php

use App\Jobs\AlarmDarkProbeRegions;
use App\Models\ProbeRegionHealth;
use App\Services\Monitoring\LocalProbeEngine;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Probe region health table backing {@see ProbeRegionHealth}: one row per
 * proxy region, updated by {@see LocalProbeEngine} on every attempt,
 * platform-wide rather than per monitor.
 *
 * `monitors.last_probe_error` already records a refusal, but it is the wrong
 * home for a dead REGION: a whole region going dark produces eight identical
 * per-monitor rows and no fleet signal, and the public symptom is silence
 * (no fresh reading) rather than an error a tenant-facing surface would ever
 * show. This table is the one place that fact is visible.
 *
 * `region` is UNIQUE because there is exactly one health signal per region,
 * mirroring `proxy_sources.region`'s own uniqueness for the same reason.
 *
 * `healthy_proxy_count` is documented as "as of the last refresh" in the
 * plan, but the refresher that owns the refresh cadence is not part of this
 * step; the engine instead reads a live count from `proxies` on every write,
 * so the column is never staler than the last attempted probe.
 *
 * `consecutive_empty_intervals` counts REFUSALS only (an attempt that
 * produced no verdict at all), not `down` readings: a target the engine
 * reached and found down still proves the region's exits work. `alarmed_at`
 * is the exactly-once guard for {@see AlarmDarkProbeRegions}: it is
 * set when the alarm fires for the CURRENT run of empty intervals and
 * cleared the moment a probe succeeds again, so a region that dies twice
 * alarms twice rather than once for the whole table's lifetime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probe_region_health', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            $table->string('region', 32)->unique();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->unsignedInteger('healthy_proxy_count')->default(0);
            $table->unsignedInteger('consecutive_empty_intervals')->default(0);
            $table->timestampTz('alarmed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probe_region_health');
    }
};
