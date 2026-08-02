<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled-maintenance-monitors pivot: the affected components of a
 * maintenance window, mirroring the `incident_monitors` shape. Tenancy flows
 * through the two cascading foreign keys, so the pivot carries no `team_id`
 * of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_maintenance_monitors', function (Blueprint $table): void {
            MigrationHelper::foreignKey($table, 'scheduled_maintenance_id')
                ->constrained('scheduled_maintenances')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'monitor_id')
                ->constrained('monitors')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->primary([
                'scheduled_maintenance_id',
                'monitor_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_maintenance_monitors');
    }
};
