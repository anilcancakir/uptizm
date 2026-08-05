<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `monitor_checks.assertions_passed` stops certifying a verdict nobody measured.
 *
 * The column shipped as `boolean NOT NULL DEFAULT TRUE`, from a migration written
 * when nothing wrote to it. Now that the edge evaluates assertions and reports the
 * outcome, that shape is a claim rather than a reading: a monitor with no
 * `assertion_rules` has no verdict at all, and there is no boolean value for "not
 * evaluated". Left as it was, every check of every monitor that asserts nothing
 * records "every assertion passed", and a status page or an SLO reading the column
 * would cite a result that was never measured.
 *
 * DROPPING THE DEFAULT MATTERS AS MUCH AS DROPPING NOT NULL, and it is the half
 * that is easy to forget. A nullable column that still defaults to TRUE tells the
 * same lie to every insert that does not name it, and an insert that has nothing
 * to say is exactly the insert that does not name it.
 *
 * The wire side is shaped so this column cannot be written by accident: the worker
 * sends ONE nullable `assertions` object, so "no rules" and "rules ran" are the
 * only two shapes that exist and a verdict never travels without its outcomes.
 *
 * MEASURED, NOT ASSUMED. `monitor_checks` is a TimescaleDB hypertable in
 * production, and NOT NULL plus the default are materialized on every CHUNK as
 * well as on the parent, so neither drop could be assumed to propagate. Measured
 * on PostgreSQL 17.10 + TimescaleDB 2.26.3 against a hypertable holding four
 * chunks: the statement below clears `attnotnull` and the default expression on
 * the parent AND on all four chunks, `relfilenode` is unchanged on every one of
 * them (no chunk rewrite, so no ACCESS EXCLUSIVE rewrite of a large table in
 * production), and a row inserted afterwards without the column lands in a chunk
 * and reads back NULL. SQLite reaches the same end state by a different route
 * (the driver has no ALTER COLUMN at all, so Laravel rebuilds the table), which is
 * the reason the assertions in `AssertionOutcomeTest` read the database
 * catalogue on both engines rather than trusting either one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One `change()` for both engines. On PostgreSQL it compiles to
        // `alter column ... type boolean, drop not null, drop default`, and the
        // type clause is a no-op the planner skips because the type is unchanged
        // (measured: no chunk was rewritten). On SQLite it rebuilds the table,
        // because that driver cannot alter a column in place.
        Schema::table('monitor_checks', function (Blueprint $table): void {
            $table->boolean('assertions_passed')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rolling back re-fabricates exactly the verdict this migration removed:
        // NOT NULL cannot be restored over rows that legitimately hold NULL, so
        // every check that evaluated nothing becomes one that passed everything,
        // and afterwards the two are indistinguishable. That is what returning to
        // the previous schema means here, and it is lossy in one direction only.
        DB::table('monitor_checks')
            ->whereNull('assertions_passed')
            ->update(['assertions_passed' => true]);

        Schema::table('monitor_checks', function (Blueprint $table): void {
            $table->boolean('assertions_passed')->default(true)->change();
        });
    }
};
