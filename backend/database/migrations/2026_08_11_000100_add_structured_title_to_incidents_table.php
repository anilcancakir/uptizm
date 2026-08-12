<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives an incident title a STRUCTURE beside its text, so a surface that knows
 * its reader's language can render the sentence instead of shipping whatever
 * English the backend happened to compose.
 *
 * `title_key` names the catalogue entry (`incidents.monitor_down` and its five
 * siblings, all spelled in `App\Services\Monitoring\IncidentTitle`) and
 * `title_params` carries the display-ready values it interpolates. `title` keeps
 * holding the English render, because the search filter, the LLM prompts and
 * every reader without a locale still need a real sentence.
 *
 * Both columns are nullable and the NULL state is the whole contract: a null
 * `title_key` means a human authored the title, which is also exactly what a row
 * written before this migration looks like. That reading is what makes old rows
 * correct with no backfill.
 *
 * Neither column is indexed, and that is deliberate rather than pending:
 * nothing queries by key, they are only ever read alongside the row that
 * carries them.
 *
 * `status_pages.locale` ships here rather than in its own migration because it
 * is the same seam from the other end: the language a public page renders those
 * titles in. NULL means the deployment default (`app.default_locale`), so an
 * existing page keeps rendering byte for byte what it renders today.
 *
 * No key is added, so `MigrationHelper` is not involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('title_key', 64)->nullable();
            $table->jsonb('title_params')->nullable();
        });

        Schema::table('status_pages', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn([
                'title_key',
                'title_params',
            ]);
        });

        Schema::table('status_pages', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};
