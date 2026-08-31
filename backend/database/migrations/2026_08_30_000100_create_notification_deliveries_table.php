<?php

use App\Models\NotificationChannel;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification deliveries table: one row per attempted send through a
 * team-scoped {@see NotificationChannel}, written for audit and
 * a future SLA read.
 *
 * Deliberately a PLAIN table, not a TimescaleDB hypertable: a hypertable
 * forces the time column into the primary key and every unique constraint
 * (see `monitor_checks`'s composite `(id, checked_at)` key), which fights
 * the FK shape below, and `add_retention_policy` silently no-ops on a box
 * without the extension. Volume is bounded by incidents x enabled channels
 * x lifecycle events, further collapsed by the 60s per-incident throttle:
 * hundreds of rows a week.
 *
 * `channel_id` is nullable with `nullOnDelete()` rather than `cascadeOnDelete()`:
 * the audit trail is the point, so a deleted channel must not take its
 * delivery history with it. `team_id` (never nulled) plus the denormalised
 * `channel_type` are what keep the row legible once `channel_id` is null.
 *
 * There is deliberately NO `attempt` column: neither notification event this
 * table is fed from carries a retry count and the writing listener is
 * synchronous, so the column could only ever hold its default and would read
 * as a fact while being a constant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'channel_id')
                ->nullable()
                ->constrained('notification_channels')
                ->nullOnDelete();

            $table->string('channel_type', 16);
            $table->string('notification_type');
            $table->string('event');
            $table->string('outcome', 16);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('exception_class', 191)->nullable();
            $table->boolean('is_test')->default(false);

            $table->timestamps();

            $table->index([
                'team_id',
                'created_at',
            ]);
            $table->index([
                'channel_id',
                'created_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
