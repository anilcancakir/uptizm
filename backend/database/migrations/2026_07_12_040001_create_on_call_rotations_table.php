<?php

use App\Models\OnCallSchedule;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-call rotations table: one ordered slot per {@see OnCallSchedule},
 * naming the responder (`user_id`) who holds the slot for `shift_hours` before
 * the ring advances to the next `position`.
 *
 * `(schedule_id, position)` is unique so a schedule cannot have two
 * responders claiming the same ring slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('on_call_rotations', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'schedule_id')
                ->constrained('on_call_schedules')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('shift_hours')->default(24);

            $table->timestamps();

            $table->unique([
                'schedule_id',
                'position',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('on_call_rotations');
    }
};
