<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status-page metrics pivot: which metric of a monitor (e.g. response time,
 * uptime percentage) is charted on a status page. `metric_key` identifies the
 * metric within the monitor's data, not a column of its own; the
 * `(status_page_id, monitor_id, metric_key)` unique constraint prevents the
 * same chart from being added twice.
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('status_page_metrics');
    }
};
