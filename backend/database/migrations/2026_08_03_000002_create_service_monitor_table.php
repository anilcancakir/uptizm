<?php

use App\Models\Monitor;
use App\Models\Service;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service-monitor pivot: which of uptizm's own monitors provide the
 * first-class own-measurement for a catalog service.
 *
 * Mirrors `status_page_monitors` exactly (composite primary key, no separate
 * id column) so the shared, customer-facing `monitors` table needs no
 * `service_id` column at all; see {@see Service::monitors()} and
 * the inverse {@see Monitor::services()}.
 *
 * The optional `label` lets the catalog rename a monitor for public display
 * (e.g. "API endpoint" instead of the monitor's internal name) without
 * renaming the underlying monitor, the same purpose `custom_label` serves on
 * `status_page_monitors`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_monitor', function (Blueprint $table): void {
            MigrationHelper::foreignKey($table, 'service_id')
                ->constrained('services')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'monitor_id')
                ->constrained('monitors')
                ->cascadeOnDelete();

            $table->string('label')->nullable();

            $table->timestamps();

            // Composite PK (no separate id column): BelongsToMany::attach() inserts
            // pivot rows via the query builder without firing a uuid-generation hook,
            // so a uuid id with no default would NOT-NULL-fail; matches
            // status_page_monitors.
            $table->primary([
                'service_id',
                'monitor_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_monitor');
    }
};
