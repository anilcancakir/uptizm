<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitor metrics table: per-monitor CUSTOM metric definitions.
 *
 * System metrics (response time and friends) are computed directly from
 * `monitor_checks`, so only user-defined custom metrics earn a definition row
 * here plus samples in `monitor_metric_values`.
 *
 * `(monitor_id, key)` is unique so metric values can be joined across checks by
 * key without ambiguity. `team_id` is denormalized to support tenant-scoped
 * metric-library queries without a join through monitors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_metrics', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'monitor_id')
                ->constrained('monitors')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();

            $table->string('group_name')->nullable();
            $table->string('label');
            $table->string('key', 40);
            $table->string('type', 16);
            $table->string('source', 16)->nullable();
            $table->text('extraction_path')->nullable();
            $table->string('unit', 50)->nullable();
            $table->string('threshold_direction', 16)->nullable();
            $table->decimal('warn_bound', 20, 6)->nullable();
            $table->decimal('critical_bound', 20, 6)->nullable();
            $table->unsignedInteger('display_order')->default(0);

            $table->timestamps();

            $table->unique([
                'monitor_id',
                'key',
            ]);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_metrics');
    }
};
