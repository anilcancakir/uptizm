<?php

use App\Models\Monitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `last_manual_check_at`: the cooldown marker for `POST
 * api/v1/monitors/{id}/test`, whose cooldown constant lives on
 * {@see Monitor::MANUAL_CHECK_COOLDOWN_SECONDS}.
 *
 * It is claimed by a single conditional UPDATE rather than read-then-written,
 * so two concurrent manual-check requests cannot both pass the gate and
 * queue a job each. Additive and NULL for every existing row: a monitor
 * that has never been manually tested is simply off cooldown.
 *
 * No key is added here, so `MigrationHelper` is not involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->timestampTz('last_manual_check_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('last_manual_check_at');
        });
    }
};
