<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push devices table: one row per push SUBSCRIPTION a person's client has
 * reported, so the escalation ladder can tell a responder whose phone rings
 * from one whose phone cannot.
 *
 * None of this is knowable from the server. The permission, the opt-in flag and
 * the subscription id all live on the device, and OneSignal accepts a push for
 * an unreachable subscription without complaint, so the only evidence there has
 * ever been is the client's own report.
 *
 * `subscription_id` is the device key and is NULLABLE, because "this device
 * holds no address" is one of the states worth recording: it is what a fresh
 * install and a device mid-logout both look like. The unique index therefore
 * only constrains the addressed rows on PostgreSQL (two NULLs are distinct
 * there), which is harmless: a subscription-less row can never claim
 * reachability, so a duplicate of one changes no answer.
 *
 * `external_id` is the alias the DEVICE reports carrying, kept beside `user_id`
 * rather than derived from it. They are different facts: `user_id` is who
 * posted the report, `external_id` is who OneSignal will deliver to, and a
 * device still subscribed as the previous person on a shared phone is exactly
 * the case where the two disagree.
 *
 * Two timestamps, on purpose. `captured_at` is the device's own clock, kept for
 * diagnosis; `reported_at` is this server's, and it is the one freshness reads,
 * because a claim about how recently a phone was heard from must not be
 * forgeable by the phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('external_id')->nullable();
            $table->string('subscription_id')->nullable();
            $table->string('reachability', 16);
            $table->timestamp('captured_at');
            $table->timestamp('reported_at');

            $table->timestamps();

            $table->unique([
                'user_id',
                'subscription_id',
            ]);
            // The escalation read: this user's devices, narrowed by how
            // recently each was heard from.
            $table->index([
                'user_id',
                'reported_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
