<?php

use App\Models\OnCallOverride;
use App\Models\OnCallRotation;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-call schedules table: a team-scoped, named on-call ring that owns an
 * ordered set of {@see OnCallRotation} rows plus its
 * {@see OnCallOverride} rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('on_call_schedules', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('timezone', 64)->default('UTC');

            $table->timestamps();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('on_call_schedules');
    }
};
