<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `locale`: the language a subscriber was reading the page in at the
 * moment they subscribed, so every subsequent mail and result page can answer
 * in it instead of re-deriving it from the page's current language.
 *
 * Nullable for the ROWS THAT PREDATE IT, not for new ones. A subscribe request
 * never fails over a language code (a subscriber must never be refused for one),
 * so an unsupported or absent submitted value takes the page's own canonical
 * language and the column receives a concrete string; null is what every row
 * written before this column existed carries, and it renders through the
 * deployment default at every subscriber-facing surface. Additive, no backfill,
 * since none of those rows holds an honest answer to "what language was this
 * visitor reading".
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
