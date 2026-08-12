<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `locale`: the language a subscriber was reading the page in at the
 * moment they subscribed, so every subsequent mail and result page can answer
 * in it instead of re-deriving it from the page's current language.
 *
 * Nullable, deliberately: an unsupported or absent submitted value is stored
 * as null rather than rejected (a subscriber must never fail to subscribe
 * over a language code), and a null renders through the deployment default at
 * every subscriber-facing surface. Additive and NULL for every existing row;
 * no backfill, since no row written before this column existed carries an
 * honest answer to "what language was this visitor reading".
 *
 * No key is added here, so `MigrationHelper` is not involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_page_subscribers', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('status_page_subscribers', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
