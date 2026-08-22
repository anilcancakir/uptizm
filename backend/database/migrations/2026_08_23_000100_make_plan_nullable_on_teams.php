<?php

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `teams.plan` stops naming a tier in order to say that no tier is owed.
 *
 * Every revocation used to write `'free'`, which is a PROXY: four feeders named
 * the cheapest tier this application sells because the column had no way to say
 * "the rail says this customer is owed nothing". The two meanings then read
 * identically, so a revocation ranked SAME against a team already on `free` and
 * slipped past the rule that stops one rail revoking what another granted, and
 * the moment this code moves into a package the proxy would have to become a
 * `free_tier` config key that every adopter is free to leave unset. NULL says it
 * without a catalogue.
 *
 * THE DEFAULT STAYS, which is the opposite of the call made in
 * `2026_08_05_100000_make_assertions_passed_nullable_on_monitor_checks.php`.
 * There the default was a fabricated verdict and dropping it mattered as much as
 * dropping NOT NULL. Here `'free'` is a real tier this product sells: a team
 * created without a plan genuinely is on the free tier, so the default is an
 * honest answer to an INSERT that says nothing, and only a revocation writes
 * NULL.
 *
 * NO BACKFILL, deliberately. Rewriting the existing `'free'` rows to NULL would
 * be a data change wearing a schema change's clothes, and it cannot be done
 * correctly: this migration cannot tell a team that holds the free tier from one
 * a rail revoked back to it. So for a while the same meaning is stored two ways,
 * which costs nothing downstream because {@see Team::entitledPlan()}
 * reads both as `Plan::Free` and only the arbitration reader, which wants the
 * distinction, reads the raw column.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One `change()` for both engines. On PostgreSQL it compiles to `alter
        // column ... type varchar(255), drop not null, set default 'free'`; on
        // SQLite the driver rebuilds the table, because it cannot alter a column
        // in place. Every attribute the column keeps has to be re-declared:
        // `change()` writes the definition it is given, so an omitted
        // `default('free')` would silently DROP the default and leave every
        // future team on NULL, which this column now reads as "owed nothing".
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('plan')->nullable()->default('free')->change();
        });
    }

    public function down(): void
    {
        // Rolling back re-fabricates exactly the proxy this migration removed:
        // NOT NULL cannot be restored over rows that legitimately hold NULL, so
        // every team a rail revoked becomes one holding the free tier and the
        // two are indistinguishable again. That is what returning to the
        // previous schema means here, and it is lossy in one direction only.
        DB::table('teams')->whereNull('plan')->update(['plan' => 'free']);

        Schema::table('teams', function (Blueprint $table): void {
            $table->string('plan')->nullable(false)->default('free')->change();
        });
    }
};
