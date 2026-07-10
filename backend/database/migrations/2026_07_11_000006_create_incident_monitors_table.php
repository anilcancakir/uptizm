<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incident-monitors pivot: the affected components of an incident (the
 * Statuspage N:M shape). Tenancy flows through the two cascading foreign keys,
 * so the pivot carries no `team_id` of its own.
 *
 * `component_status_at_start` freezes the component status when the incident
 * opened; `component_status_current` tracks the live status so the status page
 * can render the historical and current impact side by side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_monitors', function (Blueprint $table): void {
            MigrationHelper::foreignKey($table, 'incident_id')
                ->constrained('incidents')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'monitor_id')
                ->constrained('monitors')
                ->cascadeOnDelete();

            $table->string('component_status_at_start', 32);
            $table->string('component_status_current', 32);

            $table->timestamps();

            $table->primary([
                'incident_id',
                'monitor_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_monitors');
    }
};
