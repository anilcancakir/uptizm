<?php

use App\Enums\HttpAuthType;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Models\CredentialProbeAudit;
use App\Support\Logging\EvidenceLog;
use App\Support\Monitoring\AnalysisPayload;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credential probe audits: one row per analyze request that sent an
 * operator-supplied credential to a target.
 *
 * WHY A TABLE AND NOT ONLY A LOG LINE. `POST /monitors/analyze` is a
 * credential-validity oracle by construction (a tenant can make the relay send an
 * arbitrary `Authorization` header to any public host and read the answer), and
 * unlike `POST /monitors` the request leaves no row behind. That made a log line
 * the entire control, and a log file is a weak home for one: it rotates, it
 * cannot be queried, and it does not survive a server move. This table is the
 * system of record; the line on {@see EvidenceLog::CHANNEL} is DERIVED from the
 * row it wrote ({@see MonitorController::auditCredentialledProbe()}), so the two
 * cannot silently disagree.
 *
 * FIVE FACTS AND NO SIXTH, and the omission is the security property. The
 * credential VALUE must never reach this table, because the standing decision is
 * that a credential may pass through to the relay and to the AI research turn
 * behind redaction; visibility into credential USE is what makes that acceptable,
 * and a stored secret would turn the control into a second copy of the thing being
 * protected. `auth_type` is the SCHEME name off {@see HttpAuthType}
 * (`basic`, `bearer`, `api_key`), never the secret it selects.
 *
 * `host` and not the URL, for the same reason and independently of the value: a
 * monitor target is frequently `…/health?token=…`, so the query string is dropped
 * at the boundary rather than stored, exactly as
 * {@see AnalysisPayload::displayUrl()} drops it before
 * showing a URL to a model. It is nullable because `parse_url()` can fail to find
 * a host, which request validation should already have refused; a NULL row is a
 * signal about the validator, not a value.
 *
 * NO `updated_at`, and no column that could carry an amendment. An audit row is a
 * statement about one past instant; a schema that cannot express a revision cannot
 * be talked into one.
 *
 * THE TWO DELETE RULES ARE DELIBERATELY DIFFERENT. `user_id` is `nullOnDelete`
 * because a removed operator must not delete the evidence of what they did, and
 * the accountable party is the team either way. `team_id` cascades, matching every
 * other tenant-owned table here: an offboarded tenant has no queryable identity
 * left to attribute a row to, and the evidence-channel copy of the same fact
 * outlives the row by a year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_probe_audits', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // A hostname, at its DNS maximum. Untrusted input, so it is bounded
            // where it enters the system rather than trusted to be short.
            $table->string('host', 253)->nullable();
            $table->string('auth_type', 32);

            // Written by Eloquent ({@see CredentialProbeAudit::UPDATED_AT} is
            // null), and the only timestamp there is.
            $table->timestampTz('created_at')->nullable();

            // The one read this table is for: "what did this team send, and
            // when". A per-host index is deliberately absent, because the volume
            // is a handful of rows a day and an unused index is a write cost
            // paid on a security control's own path.
            $table->index([
                'team_id',
                'created_at',
            ], 'credential_probe_audits_team_recorded_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_probe_audits');
    }
};
