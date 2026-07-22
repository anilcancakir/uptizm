<?php

use App\Enums\EscalationTargetType;
use App\Services\OnCall\EscalationDispatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Escalation is now people-only: the `channel` target type is removed from
 * {@see EscalationTargetType} because integration channels
 * (Slack/webhook) self-fire on incidents instead of being paged through the
 * escalation ladder.
 *
 * This purges any pre-existing `target_type='channel'` step so paging never
 * resolves the enum on a value the case no longer accepts (which would raise
 * an UnhandledMatchError in {@see EscalationDispatcher}).
 * The comparison is a raw string, never the enum, so it stays valid after the
 * case is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('escalation_steps')
            ->where('target_type', 'channel')
            ->delete();
    }

    public function down(): void
    {
        // Deleted channel steps are not reconstructable: the target type no
        // longer exists and their content was a bare channel name with no
        // person target. There is nothing faithful to restore.
    }
};
