<?php

use App\Enums\EscalationTargetType;
use App\Models\EscalationStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the now-inert `channel` column from {@see EscalationStep}.
 *
 * Escalation is people-only ({@see EscalationTargetType} carries
 * only `OnCall`/`User`); the earlier 2026_07_22_000100 migration already
 * purged the legacy `target_type='channel'` rows, leaving the `channel`
 * column dangling with no reader. This removes the residual column.
 *
 * `down()` re-adds the column as a nullable string, matching its original
 * shape in the 2026_07_12_030001 create migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escalation_steps', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });
    }

    public function down(): void
    {
        Schema::table('escalation_steps', function (Blueprint $table): void {
            $table->string('channel')->nullable();
        });
    }
};
