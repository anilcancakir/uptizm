<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The announce-once claim for an incident's subscriber mail.
 *
 * Mirrors `scheduled_maintenances.announced_at` and exists for the same reason:
 * the fan-out is outbound mail to third parties on the product's own sending
 * domain, so "how many times can this run" has to be answered by the database
 * rather than by how carefully the caller is written. Every way the job can run
 * twice (a worker retry, a re-dispatch, a duplicate queue delivery) resolves
 * through one conditional UPDATE that exactly one runner wins.
 *
 * SEPARATE FROM `announced_at` ON MAINTENANCE, not shared through some polymorphic
 * table: the two announcements answer different questions, and a column on the
 * row being announced is what makes the claim atomic with a single UPDATE.
 *
 * Nullable with no default and NO BACKFILL. Null means "never announced", which
 * is true of every incident that already exists: nothing has ever mailed a
 * subscriber about an incident, so there is no history to preserve. Backfilling
 * `now()` would be indistinguishable from a real announcement and would hide
 * that fact from anyone reading the column later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->timestamp('subscribers_announced_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn('subscribers_announced_at');
        });
    }
};
