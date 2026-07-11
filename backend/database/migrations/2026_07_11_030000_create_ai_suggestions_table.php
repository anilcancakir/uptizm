<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI suggestions table: the inbox of AI-proposed actions for suggest mode.
 *
 * One row is a single proposal an operator can accept or dismiss. `source`
 * records whether the proposal came from the LLM or the statistical degrade
 * path ('statistical'), so a gated/budget-exhausted run still produces a
 * suggestion. `evidence` is a REDACTED, non-secret jsonb payload (observed,
 * baseline, threshold, unit, region_votes, window); it deliberately never holds
 * raw response headers/body or error text. `dedupe_key` is unique so the same
 * signal cannot flood the inbox, and the `(team_id, status)` index backs the
 * per-team pending-inbox query. `status` is fail-safe 'pending' until an
 * operator acts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestions', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'monitor_id')
                ->constrained('monitors')
                ->cascadeOnDelete();

            // Classification of the proposal.
            $table->string('kind', 32);
            $table->string('signal', 32);
            $table->string('method', 16);
            $table->float('score');
            $table->string('severity', 16);
            $table->string('confidence', 8);

            // 'llm' | 'statistical'; the degrade path writes 'statistical'.
            $table->string('source', 12);

            $table->text('recommendation');

            // Redacted, non-secret evidence only (observed/baseline/threshold/
            // unit/region_votes/window). Never raw headers/body/error text.
            $table->jsonb('evidence');

            // Dedupe + lifecycle. Unique key stops the same signal flooding the
            // inbox; status is fail-safe 'pending' until an operator acts.
            $table->string('dedupe_key', 191)->unique();
            $table->string('status', 12)->default('pending');
            $table->timestampTz('expires_at')->nullable();

            // Set when an operator accepts the suggestion into an incident.
            MigrationHelper::foreignKey($table, 'accepted_incident_id')
                ->nullable()
                ->constrained('incidents')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('accepted_incident_id');
            $table->index([
                'team_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
    }
};
