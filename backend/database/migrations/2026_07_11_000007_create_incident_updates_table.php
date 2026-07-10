<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incident updates: the single unified incident timeline (D3), mirroring the
 * mockup's `TimelineEntry`. The public status page renders only the rows where
 * `is_public = true`; internal notes stay private.
 *
 * `actor` is the origin kind (human / ai / system) and `author` is the display
 * label shown on the timeline. `autonomous` flags an update posted by the AI
 * without human confirmation. Tenancy flows through `incident_id`, matching the
 * v2 lineage this table is derived from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_updates', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'incident_id')
                ->constrained('incidents')
                ->cascadeOnDelete();

            $table->string('actor', 16);
            $table->string('author')->nullable();
            $table->string('status', 32);
            $table->longText('message');
            $table->boolean('is_public')->default(false);
            $table->boolean('autonomous')->default(false);
            $table->timestampTz('display_at');

            $table->timestamps();

            $table->index([
                'incident_id',
                'display_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_updates');
    }
};
