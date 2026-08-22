<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives `teams.plan` the provenance and ordering discipline a second billing
 * rail requires, and gives the neutral entitlement wire a source for every
 * field it promises.
 *
 * `plan` and `plan_status` are already the single source of truth for
 * entitlement (App\Models\Team::entitledPlan()), and Cashier is one feeder of
 * that column rather than the truth itself. Today the rest of the answer is
 * read out of Cashier's `subscriptions` row: the period, the status word and
 * the price. A store-rail team has no Cashier row at all, so every field the
 * wire exposes needs storage of its own here, on the team:
 *
 * - `plan_provider` names WHICH rail granted the entitlement currently held. It
 *   is what lets a write refuse to revoke a grant some other rail made.
 * - `plan_source_event_at` is the source event's OWN timestamp, not the moment
 *   we processed it. Stores retry and cancellations arrive late, so this is the
 *   only thing that makes an out-of-order delivery detectable.
 * - `plan_provider_status` keeps the rail's own status word verbatim, for
 *   support and debugging. It is opaque text and never a gate.
 * - `plan_product_id` is the rail-native product or price identifier.
 * - `plan_current_period_end` is when the paid period ends, whether or not it
 *   renews. Deliberately not Cashier's `ends_at`, which means something else:
 *   a cancellation-effective date.
 * - `plan_renews` is the auto-renew state, and it is nullable on purpose.
 *   NULL means unknown, which is not the same claim as false.
 * - `plan_grace_period_ends_at` carries a Stripe `past_due` window or a
 *   RevenueCat `grace_period_expiration_at_ms`.
 * - `plan_manage_url` is where the customer manages this subscription: the
 *   Stripe portal URL on the web rail, RevenueCat's own
 *   `subscriber.management_url` on a store rail. Passing the destination
 *   through rather than hardcoding an Apple or Google page means a store
 *   moving that page does not need an app release.
 *
 * Every column is nullable and there is deliberately NO backfill. Nothing has
 * ever been granted by Stripe in production, so writing `stripe` onto existing
 * rows would assert a provenance that never happened, and would then license a
 * Stripe event to revoke a store grant. A NULL `plan_provider` is the honest
 * reading: this row predates any rail.
 *
 * There is also deliberately no database enum or CHECK constraint on
 * `plan_provider` or `plan_provider_status`. The provider vocabulary lives in a
 * PHP enum, and the status column exists precisely to keep a word the neutral
 * vocabulary does not have.
 *
 * The three instants are `timestampTz`, matching the 16 other load-bearing
 * instants in this schema rather than the naive `timestamp` used for
 * bookkeeping columns; the pgsql connection pins its session zone to UTC
 * (config/database.php, pinned by tests/Feature/DatabaseTimezoneTest.php),
 * which is what makes those safe to compare across rails.
 *
 * No key column is added here, so MigrationHelper is not involved.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: no existing column is read, written or altered.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('plan_provider')->nullable();
            $table->timestampTz('plan_source_event_at')->nullable();
            $table->string('plan_provider_status')->nullable();
            $table->string('plan_product_id')->nullable();
            $table->timestampTz('plan_current_period_end')->nullable();
            $table->boolean('plan_renews')->nullable();
            $table->timestampTz('plan_grace_period_ends_at')->nullable();
            $table->string('plan_manage_url', 2048)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops only the eight columns added above; `plan` and `plan_status`
     * survive a rollback because this migration never owned them.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn([
                'plan_provider',
                'plan_source_event_at',
                'plan_provider_status',
                'plan_product_id',
                'plan_current_period_end',
                'plan_renews',
                'plan_grace_period_ends_at',
                'plan_manage_url',
            ]);
        });
    }
};
