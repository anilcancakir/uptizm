<?php

use App\Models\OnCallSchedule;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-call overrides table: a temporary responder swap on an
 * {@see OnCallSchedule}, covering the schedule between
 * `starts_at` and `ends_at` instead of whoever the rotation ring would
 * otherwise pick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('on_call_overrides', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'schedule_id')
                ->constrained('on_call_schedules')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            $table->timestamps();

            $table->index([
                'schedule_id',
                'starts_at',
                'ends_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('on_call_overrides');
    }
};
