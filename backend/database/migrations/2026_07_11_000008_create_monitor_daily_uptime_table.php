<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily uptime rollup: one row per monitor per calendar day (UTC), storing the
 * precomputed uptime percentage, check counts, and worst observed status.
 *
 * A nightly job writes these rows so the public status page can render the
 * 90-day uptime strip without scanning `monitor_checks` on every request. The
 * rollup is kept long (cheap) so uptime history survives beyond raw-check
 * retention. `(monitor_id, date)` is unique so each day upserts in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_daily_uptime', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'monitor_id')
                ->constrained('monitors')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();

            $table->date('date');
            $table->decimal('uptime_percent', 5, 2)->default(0);
            $table->unsignedInteger('total_checks')->default(0);
            $table->unsignedInteger('failed_checks')->default(0);
            $table->string('worst_status', 16)->default('up');

            $table->timestamps();

            $table->unique([
                'monitor_id',
                'date',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_daily_uptime');
    }
};
