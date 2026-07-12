<?php

use App\Jobs\GenerateWeeklyDigest;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly AI digest table: one row per team per generated digest run
 * ({@see GenerateWeeklyDigest}). `summary` and `highlights` are the
 * (already allowlist-cleaned) LLM narration, or the deterministic degrade
 * text when the team is over its daily AI budget or the gateway fails.
 * `uptime_percent`/`incident_count` are the trusted aggregate stats the
 * narration is grounded in. The `(team_id, generated_at)` index backs the
 * "read the latest digest for this team" query, which never triggers
 * generation synchronously.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_digests', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();

            $table->date('week_start');
            $table->date('week_end');
            $table->float('uptime_percent');
            $table->unsignedInteger('incident_count');
            $table->string('confidence', 8);
            $table->text('summary');
            $table->jsonb('highlights');
            $table->jsonb('stripped_citations');
            $table->timestampTz('generated_at');

            $table->timestamps();

            $table->index([
                'team_id',
                'generated_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_digests');
    }
};
