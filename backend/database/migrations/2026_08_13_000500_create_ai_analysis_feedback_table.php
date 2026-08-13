<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator ratings of a stored analysis: one vote per user per analysis.
 *
 * The vote points at an `ai_incident_analyses` row rather than at the incident,
 * because "this analysis was wrong" is a statement about one answer built from
 * one set of evidence. Attaching it to the incident would silently re-target
 * the rating every time the evidence moved.
 *
 * The unique index over (`analysis_id`, `user_id`) makes a second vote an
 * update rather than a duplicate, so an operator can change their mind and the
 * helpful rate stays a count of people rather than a count of clicks.
 *
 * `note` is nullable and unused by the current UI. It exists because the only
 * thing a thumbs-down tells us today is that the answer was wrong, not what was
 * wrong with it, and adding the column later would mean a migration on a table
 * the product had already started collecting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analysis_feedback', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'analysis_id')
                ->constrained('ai_incident_analyses')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->boolean('helpful');
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['analysis_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analysis_feedback');
    }
};
