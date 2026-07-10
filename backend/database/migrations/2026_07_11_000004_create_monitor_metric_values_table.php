<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitor metric values table: one row per extracted custom-metric sample.
 *
 * The three metric shapes (numeric, status, string) share a single table via
 * nullable columns; the paired `monitor_metrics` row tells readers which column
 * to trust for a given `metric_key`.
 *
 * `check_id` is a plain UUID (no FK) so the retention policy can drop old checks
 * without cascading through metric values and so future Timescale chunks stay
 * cheap; joins happen at query time on `(monitor_id, check_id)`.
 *
 * Primary key is the composite `(id, recorded_at)` for the same hypertable
 * reason documented on `monitor_checks`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_metric_values', function (Blueprint $table): void {
            $table->uuid('id');
            $table->timestampTz('recorded_at');

            MigrationHelper::foreignKey($table, 'monitor_id')
                ->constrained('monitors')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();

            $table->uuid('check_id');
            $table->string('metric_key', 40);
            $table->decimal('numeric_value', 20, 6)->nullable();
            $table->text('string_value')->nullable();
            $table->string('status_value', 16)->nullable();
            $table->string('band', 16)->nullable();

            $table->timestamps();

            $table->primary([
                'id',
                'recorded_at',
            ]);
            $table->index([
                'monitor_id',
                'metric_key',
                'recorded_at',
            ]);
            $table->index([
                'team_id',
                'recorded_at',
            ]);
            $table->index([
                'monitor_id',
                'check_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_metric_values');
    }
};
