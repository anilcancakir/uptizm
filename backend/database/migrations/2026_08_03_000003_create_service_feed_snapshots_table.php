<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per fetch of a service's official status feed (Statuspage v2,
 * Google Cloud, or Google Workspace). Only the PARSED state and a normalized
 * content hash are stored; the raw provider payload is never archived here
 * (raw-body archiving is a deferred idea with its own failure modes, not
 * this table's contract).
 *
 * `etag` is load-bearing, not a convenience: a later ingestion step sends it
 * back as `If-None-Match` on the NEXT fetch, which is what makes the
 * conditional-request commitment (poll no faster than 60s, honour a `304`)
 * actually implementable rather than merely stated.
 *
 * `indicator`, `components` and `incidents` mirror the shape of a Statuspage
 * v2 `summary.json` response (`status.indicator`, `components[]`,
 * `incidents[]`); the Google adapters normalize into the same three fields
 * so one snapshot shape serves all three sources.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_feed_snapshots', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'service_id')
                ->constrained('services')
                ->cascadeOnDelete();

            $table->timestampTz('fetched_at');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('indicator', 32)->nullable();
            $table->jsonb('components')->default('[]');
            $table->jsonb('incidents')->default('[]');
            $table->string('content_hash_normalized', 64)->nullable();
            $table->string('etag', 255)->nullable();
            $table->string('error')->nullable();

            $table->timestamps();

            $table->index([
                'service_id',
                'fetched_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_feed_snapshots');
    }
};
