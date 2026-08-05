<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the `status_page_metrics` pivot: nothing could ever write it.
 *
 * The table shipped for a live-metrics grid that was never built. No editor
 * control ever selected a metric, no request class validated the metric list
 * the client posted, no controller persisted a pivot row, and no public view
 * rendered a metric, so every row count was zero and `StatusPage::metrics()`
 * had no caller. Publishing a metric to an anonymous visitor is its own
 * decision about what may leave the tenant boundary, so the surface goes rather
 * than waiting in the schema for someone to assume it was already thought
 * through.
 *
 * `down()` restores the table exactly as
 * `2026_07_11_020002_create_status_page_metrics_table` declared it, composite
 * primary key included: a rollback that rebuilt it with an `id` column would
 * leave the schema silently divergent from every other pivot in this database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('status_page_metrics');
    }

    public function down(): void
    {
        Schema::create('status_page_metrics', function (Blueprint $table): void {
            MigrationHelper::foreignKey($table, 'status_page_id')
                ->constrained('status_pages')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'monitor_id')
                ->constrained('monitors')
                ->cascadeOnDelete();

            $table->string('metric_key');

            $table->timestamps();

            // Composite PK (no separate id column), matching status_page_monitors +
            // incident_monitors: pivot rows attach via the query builder.
            $table->primary([
                'status_page_id',
                'monitor_id',
                'metric_key',
            ]);
        });
    }
};
