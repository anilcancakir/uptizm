<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monitor checks table: one row per probe execution per region.
 *
 * The primary key is the composite `(id, checked_at)` so the table can be
 * promoted to a TimescaleDB hypertable in a follow-up migration without
 * rewriting the schema (Timescale requires the partition column in every
 * unique constraint). `id` stays effectively unique on its own thanks to the
 * ordered UUID, so app-level lookups by id remain correct.
 *
 * `probe_run_id` is the idempotency key handed down from the Cloudflare Worker
 * relay: `(monitor_id, region, probe_run_id)` is unique so a replayed callback
 * cannot double-insert a check.
 *
 * Indexes are tuned for the hottest reads:
 *   - Monitor detail timeline: `(monitor_id, checked_at DESC)`.
 *   - Region-scoped dashboards: `(monitor_id, region, checked_at DESC)`.
 *   - Tenant-wide queries:      `(team_id, checked_at)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_checks', function (Blueprint $table): void {
            // Composite-PK time-series row: the time column is part of the key
            // so a later hypertable promotion needs no schema rewrite.
            $table->uuid('id');
            $table->timestampTz('checked_at');

            MigrationHelper::foreignKey($table, 'monitor_id')
                ->constrained('monitors')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();

            $table->string('region', 32);
            $table->string('status', 16);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_ms')->nullable();

            // Timing breakdown captured by the probe (all in milliseconds).
            $table->unsignedInteger('timing_dns_ms')->default(0);
            $table->unsignedInteger('timing_connect_ms')->default(0);
            $table->unsignedInteger('timing_tls_ms')->default(0);
            $table->unsignedInteger('timing_ttfb_ms')->default(0);
            $table->unsignedInteger('timing_download_ms')->default(0);

            $table->jsonb('response_headers')->default('{}');
            $table->text('response_body_preview')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('assertions_passed')->default(true);
            $table->jsonb('assertion_results')->nullable();
            $table->string('probe_run_id', 64)->nullable();

            $table->timestamps();

            $table->primary([
                'id',
                'checked_at',
            ]);
            // The idempotency key carries `checked_at` for the same reason the
            // primary key does: TimescaleDB refuses to promote a table whose
            // UNIQUE index omits the partitioning column, and that refusal is
            // what actually blocked the first real hypertable promotion. The
            // dedupe guarantee is unchanged, because `checked_at` is the probe's
            // own timestamp read straight off the relay payload
            // (CheckResult::fromArray, no `now()` fallback), so replaying one
            // probe_run always presents the same instant. `checked_at` goes last
            // so the leading three columns stay a usable lookup prefix.
            $table->unique([
                'monitor_id',
                'region',
                'probe_run_id',
                'checked_at',
            ]);
            $table->index([
                'team_id',
                'checked_at',
            ]);
        });

        // Descending time indexes: btree could scan an ascending index
        // backwards, but the explicit DESC ordering keeps the two hottest
        // "latest checks first" reads a pure forward scan. Both drivers accept
        // DESC in CREATE INDEX, so no driver guard is needed here.
        DB::statement(
            'CREATE INDEX monitor_checks_monitor_checked_desc_idx '
            .'ON monitor_checks (monitor_id, checked_at DESC)',
        );
        DB::statement(
            'CREATE INDEX monitor_checks_monitor_region_checked_desc_idx '
            .'ON monitor_checks (monitor_id, region, checked_at DESC)',
        );

        // SQLite parses CHECK only inside CREATE TABLE and lacks octet_length,
        // so the 10 KiB preview cap is a Postgres-only invariant.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE monitor_checks ADD CONSTRAINT response_body_preview_size '
                .'CHECK (response_body_preview IS NULL OR octet_length(response_body_preview) <= 10240)',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_checks');
    }
};
