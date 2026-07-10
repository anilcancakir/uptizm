<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitors table: the configuration for every probe the platform runs.
 *
 * Consolidated final-state shape (v2's 54-migration history is not replayed).
 * Indexes are tuned for the two hottest queries:
 *   - Tenant list views filter by `(team_id, status)`.
 *   - The scheduler fans out checks by picking rows where
 *     `next_check_at <= now()` among active monitors.
 *
 * `escalation_policy_id` is a bare UUID with no foreign key: the escalation
 * domain is deferred, so the column reserves the reference without binding to
 * a table that does not exist yet. `parent_id` self-references to model
 * component groups (an `is_group` monitor with child monitors).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();

            // Probe definition.
            $table->string('name');
            $table->string('type', 16);
            $table->string('url', 2048);
            $table->string('method', 16)->default('get');
            $table->jsonb('request_headers')->default('{}');
            $table->text('request_body')->nullable();
            $table->unsignedSmallInteger('expected_status_code')->default(200);
            $table->unsignedInteger('check_interval_sec');
            $table->unsignedSmallInteger('timeout_sec')->default(30);
            $table->jsonb('regions')->default('[]');
            $table->jsonb('auth_config')->nullable();
            $table->jsonb('assertion_rules')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->string('ai_mode', 16)->default('off');

            // Denormalized runtime state (updated by the check pipeline).
            $table->string('status', 16)->default('active');
            $table->string('last_status', 16)->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->timestampTz('next_check_at')->nullable();
            $table->unsignedSmallInteger('consecutive_fails')->default(0);
            $table->unsignedSmallInteger('incident_threshold')->default(2);

            // Reliability targets + escalation (escalation domain deferred).
            $table->double('slo_target')->nullable();
            $table->uuid('escalation_policy_id')->nullable();

            // Status-page + component-group presentation.
            $table->boolean('show_on_status_page')->default(false);
            $table->boolean('only_show_if_degraded')->default(false);
            $table->boolean('is_group')->default(false);
            // Self-reference kept as a bare UUID (no FK): a self-referencing
            // foreign key cannot be added in the same CREATE on Postgres (the
            // primary key is not yet visible to the ALTER that adds it), and the
            // spec asks for a "self-uuid" alongside the FK-less escalation ref.
            $table->uuid('parent_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index([
                'team_id',
                'status',
            ]);
            $table->index('next_check_at');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};
