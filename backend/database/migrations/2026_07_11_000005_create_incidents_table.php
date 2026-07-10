<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidents table: one row per unhealthy window, unified across the signal
 * sources that can open it (threshold / anomaly / manual).
 *
 * The affected components live in the `incident_monitors` pivot;
 * `primary_monitor_id` is the denormalized primary-affected hint so the list
 * view can badge an incident without loading the pivot. `ai_owned` distinguishes
 * AI-driven incidents from user-threshold ones for badging and filtering.
 *
 * Lifecycle follows the mockup's simplified state machine
 * (detected/investigating/identified/monitoring/resolved); enum wire values are
 * snake_case strings shared with the Flutter/magic client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'primary_monitor_id')
                ->nullable()
                ->constrained('monitors')
                ->nullOnDelete();

            $table->string('title', 200);
            $table->string('impact', 16);
            $table->string('severity', 16);
            $table->string('signal_source', 32);
            $table->string('lifecycle', 32)->default('detected');
            $table->boolean('ai_owned')->default(false);
            $table->string('trigger_metric_key', 40)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('resolved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index([
                'team_id',
                'lifecycle',
                'started_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
