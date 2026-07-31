<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitor content versions: one row per DISTINCT page content per monitor.
 *
 * Not a time series. A row is revised in place every time the same content is
 * seen again (`last_seen_at`), so unlike `monitor_checks` this table keeps its
 * `updated_at` and is deliberately NOT promoted to a TimescaleDB hypertable:
 * Timescale requires the partitioning column in every unique index (the
 * constraint documented at `CheckPersistenceService.php:27-29`), which would make
 * the address key below unenforceable, and there is no time dimension to
 * partition on in the first place.
 *
 * The two hashes are not two views of the same bytes. `content_hash` is the raw
 * hash of the stored bytes: the version's ADDRESS and the stem of its blob
 * filename. `content_hash_normalized` is the change signal and the LOOKUP key the
 * archive reads to answer "have we seen this content before".
 *
 * There is deliberately NO `storage_path` column. The blob path is always derived
 * from `(team_id, content_hash)` through one validating helper, so no stored
 * string can ever become the target of a delete on a remote that holds the only
 * PostgreSQL backups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_content_versions', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'monitor_id')
                ->constrained('monitors')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();

            // 64 characters each because SHA-256 hex is exactly that long. No
            // foreign key on `content_hash`: it is an address, not a relation.
            $table->string('content_hash', 64);
            $table->string('content_hash_normalized', 64);

            // The RAW DECODED body length in bytes, NOT the gzipped size of the
            // blob on disk. The claim writes it from the decoded body and the
            // finalize step must not overwrite it with the compressed length, or
            // this one column would mean two different things across rows.
            $table->unsignedInteger('byte_size');

            // The target chooses this header, so it is untrusted input; it is
            // truncated to fit at the boundary where it enters the system.
            $table->string('content_type', 128)->nullable();
            $table->boolean('truncated')->default(false);

            // Part of BOTH unique keys below, so bumping a normalization rule
            // starts a fresh chain instead of colliding with the old one.
            $table->unsignedSmallInteger('normalizer_version');

            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            $table->timestamps();

            // Both keys are named explicitly and kept short. Laravel's generated
            // name for the normalized key would be 85 characters (`Blueprint`
            // joins the table, every column and the index type), past
            // PostgreSQL's 63-byte identifier limit, so production would receive
            // a silently truncated name while the SQLite test suite saw the full
            // one. Nothing in this codebase has hit that yet: the longest
            // existing name is exactly 63.
            //
            // Both carry `normalizer_version` for a reason that is invisible at
            // the call site: `insertOrIgnore` compiles to `on conflict do
            // nothing` with NO conflict target, so the claim cannot tell which of
            // these two indexes it collided on. Leave the version out of the
            // address key and, after a version bump, every already-archived body
            // still carries its old raw hash: the claim gets silently ignored on
            // the address index, the follow-up lookup by normalized hash finds no
            // row, and archiving stops with no error anywhere.

            // The address key. One row per stored blob, so two parallel writes of
            // identical bytes cannot produce sibling rows and let the prune delete
            // a blob another row still claims.
            $table->unique([
                'monitor_id',
                'content_hash',
                'normalizer_version',
            ], 'monitor_content_versions_raw_hash_idx');

            // The change-decision key, and what makes a version stored ONCE PER
            // MONITOR rather than once per region: `ScheduleMonitorChecks` fans
            // out one check per region and a page churns a per-request token, so a
            // per-region chain would give a 5-region monitor 5 blobs of the same
            // logical content.
            $table->unique([
                'monitor_id',
                'content_hash_normalized',
                'normalizer_version',
            ], 'monitor_content_versions_norm_hash_idx');

            // The nightly prune scans this column for the retention window.
            $table->index('last_seen_at', 'monitor_content_versions_last_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_content_versions');
    }
};
