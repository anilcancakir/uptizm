<?php

use App\Http\Controllers\Api\V1\MonitorController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `last_manual_check_at`: the cooldown marker for `POST
 * monitors/{id}/test` (see {@see MonitorController::test()}).
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
