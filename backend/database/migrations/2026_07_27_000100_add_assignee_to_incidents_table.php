<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the incident assignee: the team member currently driving the response.
 *
 * Nullable because an incident is unassigned until an operator picks an owner
 * (and can be unassigned again), and `nullOnDelete` because a removed user must
 * never cascade an incident away: the incident record outlives the account, it
 * simply falls back to unassigned.
 *
 * The membership constraint (the assignee has to belong to the incident's team)
 * is enforced at the write boundary, not by the schema: team membership lives in
 * `team_user` plus `teams.user_id`, which no single FK can express.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            MigrationHelper::foreignKey($table, 'assigned_to_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_to_user_id');
        });
    }
};
