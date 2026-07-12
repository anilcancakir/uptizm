<?php

use App\Models\EscalationPolicy;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalation steps table: one ordered step per {@see EscalationPolicy},
 * firing `delay_minutes` after the previous step (or after incident open, for
 * the first step) against `target_type`.
 *
 * `target_id` is a bare UUID (no foreign key) since its meaning depends on
 * `target_type`: a user id when targeting a user, unused otherwise. The
 * `on_call` target has no direct reference, it is resolved at dispatch time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_steps', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'escalation_policy_id')
                ->constrained('escalation_policies')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('delay_minutes');
            $table->string('target_type', 16);
            $table->uuid('target_id')->nullable();
            $table->string('channel')->nullable();

            $table->timestamps();

            $table->index([
                'escalation_policy_id',
                'position',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_steps');
    }
};
