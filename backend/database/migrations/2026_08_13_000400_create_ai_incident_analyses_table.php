<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stored post-incident analyses: one row per answer the analyser produced for
 * an incident, keyed by the evidence it was produced from.
 *
 * Before this table the analysis was recomputed on every read, which spent one
 * AI budget unit per page open and meant three responders looking at the same
 * incident paid for three answers to the same question. It also made the
 * Helpful / Not helpful buttons unimplementable: a vote has to attach to a
 * specific answer, and an answer that only ever existed inside one HTTP
 * response has no identity to attach it to.
 *
 * `evidence_fingerprint` is what makes the read-through honest. It hashes the
 * material evidence rather than the row's age, so a stored analysis is served
 * only while the evidence still says what it said when the model answered, and
 * a new check that actually changes the picture produces a new row instead of
 * re-serving a stale claim. The unique index over
 * (`incident_id`, `evidence_fingerprint`) is the lookup and the dedupe at once.
 *
 * Retention, stated as it actually behaves rather than as it was first
 * described. One row per (incident, fingerprint), and a re-ask on the SAME
 * fingerprint UPDATES that row instead of adding another: the unique index says
 * so, and `IncidentAnalysisService::storedAnalysisFor()` uses `updateOrCreate()`.
 * When such an update changes the text, the ratings attached to it are deleted,
 * because a vote is a statement about words and those words are gone.
 *
 * So history accumulates across DIFFERENT evidence and is replaced within the
 * same evidence. The docblock here originally claimed rows were append-only and
 * never overwritten, which was the intention and never the code; a reader who
 * believed it would have expected every refresh to be recoverable.
 *
 * Only a MODEL-authored analysis is ever written here. The deterministic
 * baseline the service falls back to when the budget is spent or the provider
 * is unreachable is the absence of an answer rather than an answer, so storing
 * it would pin a non-answer to a fingerprint and re-serve it to every later
 * reader, and would offer a Helpful button over text no model wrote. That is
 * also why there is no `degrade_reason` column: on this table it would be null
 * on every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_incident_analyses', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'incident_id')
                ->constrained('incidents')
                ->cascadeOnDelete();

            $table->string('evidence_fingerprint', 64);

            // The full wire shape the endpoint returns, stored as it was
            // rendered. Keeping the composed answer rather than its parts means
            // a later change to the result object cannot silently reinterpret
            // an old row, and the client's contract stays one decoder.
            $table->json('result');

            $table->timestamps();

            $table->unique(['incident_id', 'evidence_fingerprint']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_incident_analyses');
    }
};
