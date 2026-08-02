<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `opt_in_confirmed_at`: the provenance of a subscriber's consent, written
 * ONLY by the public confirm link.
 *
 * The column exists because the two populations already in the table cannot be
 * told apart. An operator add wrote `confirmed_at` with no `confirmed_token`,
 * and a completed public opt-in wrote `confirmed_at` AND nulled the token, so
 * both rows are byte-identical on both columns and no discriminator over the old
 * schema separates a proven click from a pasted address. The announcement path
 * selects on THIS column instead, so a recipient set is what this system
 * recorded a click for rather than what a row shape suggests.
 *
 * Additive and NULL for every existing row, deliberately: there is NO backfill,
 * not even a timestamp heuristic, because inheriting unprovable consent is
 * exactly what would turn the announcement into an open relay. The cost is that
 * no subscriber that exists today is announced to until they confirm again,
 * which costs nothing in practice since nothing has ever mailed these rows.
 *
 * No key is added here, so `MigrationHelper` is not involved. No index either:
 * recipients are always resolved within one status page, and the existing
 * `(status_page_id, email)` unique constraint already leads with that column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_page_subscribers', function (Blueprint $table): void {
            $table->timestampTz('opt_in_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('status_page_subscribers', function (Blueprint $table): void {
            $table->dropColumn('opt_in_confirmed_at');
        });
    }
};
