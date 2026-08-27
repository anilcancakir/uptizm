<?php

use App\Services\OnCall\EscalationDispatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two policy-level paging flags the editor has been collecting all along.
 *
 * The Flutter escalation editor ships a "Repeat last rung until acknowledged"
 * switch and a "Use as default policy" switch. Both were collected, both
 * reported success, and neither was ever sent, because the table had nowhere to
 * put them. On an alerting product the first of those is the difference between
 * paging once and paging until somebody answers, so the switch was making a
 * promise about who gets woken that nothing in the system kept.
 *
 * `is_default` FORMALISES A FALLBACK THAT ALREADY EXISTS. When a monitor pins
 * no `escalation_policy_id`, {@see EscalationDispatcher}
 * already falls back to the team's earliest-created policy. That is a real
 * choice, just an implicit one nobody can see or change: a team that wanted a
 * different ladder to be the fallback had to delete and recreate policies until
 * creation order happened to agree. The column lets them say it instead, and
 * the old ordering stays as the answer when no policy is marked.
 *
 * Both default to false, which is exactly today's behaviour: no ladder repeats,
 * and every team keeps the creation-order fallback until somebody marks a
 * policy. So no backfill is needed and no existing team's paging changes on
 * deploy.
 *
 * UNIQUENESS FOR `is_default` IS ENFORCED IN THE WRITE PATH, not by a partial
 * unique index. A `UNIQUE (team_id) WHERE is_default` would be the tighter
 * spelling, but this suite runs against both SQLite and PostgreSQL in CI and
 * Laravel's schema builder has no portable partial-unique helper. The write
 * path demotes the team's other policies inside a transaction instead (see
 * EscalationPolicyController), which also reads better for the operator:
 * marking a second policy as default MOVES the default rather than answering
 * 422 and asking them to go and unmark the first one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escalation_policies', function (Blueprint $table): void {
            $table->boolean('repeat_last_step')->default(false)->after('name');
            $table->boolean('is_default')->default(false)->after('repeat_last_step');
        });

        // Reads for the fallback are `where(team_id)->where(is_default)`, one
        // row out of a handful per team. The index earns its keep on the
        // dispatcher's hot path (every incident that opens resolves a policy),
        // not on the table's size.
        Schema::table('escalation_policies', function (Blueprint $table): void {
            $table->index(['team_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::table('escalation_policies', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'is_default']);
            $table->dropColumn(['repeat_last_step', 'is_default']);
        });
    }
};
