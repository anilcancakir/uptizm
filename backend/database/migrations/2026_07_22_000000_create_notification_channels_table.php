<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification channels table: a team-scoped Slack or generic-webhook
 * integration that fires on incidents, filtered by `severity`.
 *
 * `credentials` is created as `text` (not `jsonb`) so it can hold the
 * `encrypted:array` cast's opaque ciphertext string directly; unlike
 * `monitors.auth_config`, there is no prior jsonb column to migrate away
 * from since this table is new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('channel_type', 16);
            $table->text('credentials');
            $table->boolean('is_enabled')->default(true);
            $table->string('severity', 16)->default('all');

            $table->timestamps();

            $table->index([
                'team_id',
                'channel_type',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
