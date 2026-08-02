<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled maintenance windows: a planned, publicly announced period during
 * which one or more monitors are expected to be unhealthy on purpose.
 *
 * A window has no lifecycle enum: whether it is upcoming, in progress, or
 * past is derived from `starts_at`/`ends_at` against the current time, never
 * stored. `suppress_alerts` (Step 9) tells the alert pipeline to hold paging
 * while the window is active; `announced_at` (Step 8) is the announce-once
 * guard, set the first time the subscriber mail actually sends.
 *
 * The affected components live in the `scheduled_maintenance_monitors`
 * pivot, mirroring `incident_monitors`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_maintenances', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'status_page_id')
                ->constrained('status_pages')
                ->cascadeOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->boolean('suppress_alerts')->default(true);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('announced_at')->nullable();

            $table->timestamps();

            $table->index([
                'team_id',
                'starts_at',
                'ends_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_maintenances');
    }
};
