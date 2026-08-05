<?php

use App\Models\MonitorMetric;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a `string`-typed {@see MonitorMetric} its own threshold
 * vocabulary: three configurable value lists plus a fallback band for a
 * value that matches none of them.
 *
 * `ok_values` / `warn_values` / `critical_values` are `jsonb` so the arrays
 * round-trip through the plain `'array'` cast the same way `Monitor::regions`
 * already does; each defaults to `'[]'` so an inert string metric (nothing
 * configured yet) still reads back as an empty array rather than null.
 * `unmatched_band` is nullable: leaving it unset means an extracted value
 * outside every list simply records no band, exactly like a numeric metric
 * with no `threshold_direction`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_metrics', function (Blueprint $table): void {
            // No `after()`: it is a MySQL-only positional hint that the Postgres
            // grammar drops, so declaring one would imply a column order this
            // deploy's engine never honours.
            $table->jsonb('ok_values')->default('[]');
            $table->jsonb('warn_values')->default('[]');
            $table->jsonb('critical_values')->default('[]');
            $table->string('unmatched_band', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitor_metrics', function (Blueprint $table): void {
            $table->dropColumn([
                'ok_values',
                'warn_values',
                'critical_values',
                'unmatched_band',
            ]);
        });
    }
};
