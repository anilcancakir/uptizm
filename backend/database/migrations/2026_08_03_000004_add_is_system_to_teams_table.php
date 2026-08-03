<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag the one internal team that owns the public service catalog's monitors.
 *
 * The flag exists so "this row is not a customer" is a greppable predicate
 * instead of a name or an email comparison scattered across call sites. Two
 * consumers depend on it today: `App\Support\Services\SystemTeam::resolve()`
 * finds the row by it, and `App\Services\Billing\PlanGate::limits()`
 * short-circuits the plan catalog for it, so a plan-gated write path can never
 * evaluate uptizm's own team against the Free tier and refuse. References are
 * spelled out rather than imported because a migration importing three app
 * classes for its docblock alone reads worse than the paths do.
 *
 * `false` by default, which is the only safe default: a customer team that
 * somehow acquired `true` would be handed unlimited plan caps. That is also why
 * `is_system` is deliberately absent from `App\Models\Team::$fillable` and is
 * written with `forceFill()` in the resolver alone.
 *
 * Additive and guarded, matching
 * `2026_07_27_000000_add_ai_analysis_trials_used_to_teams_table.php`: `teams` is
 * created by the magic-starter-laravel package migration, so the table is shared
 * and never recreated here.
 *
 * Pinned by `tests/Feature/Services/SystemTeamTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            if (! Schema::hasColumn('teams', 'is_system')) {
                $table->boolean('is_system')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('teams', 'is_system')) {
            Schema::table('teams', function (Blueprint $table): void {
                $table->dropColumn('is_system');
            });
        }
    }
};
