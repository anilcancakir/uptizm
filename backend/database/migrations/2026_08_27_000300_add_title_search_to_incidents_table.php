<?php

use App\Models\Incident;
use App\Support\SearchText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the incident roster something an operator can actually search.
 *
 * `title` holds the English sentence, pinned at open time so the LLM prompts
 * and any locale-less reader have one stable string. That is right for those
 * readers and wrong for the only one who types into a search box: the operator
 * sees `title_key` + `title_params` RENDERED in the app's language, so a
 * Turkish reader searching the words on their screen was searching a sentence
 * the column has never held.
 *
 * `title_search` is every form of the title at once, folded through
 * {@see SearchText::fold()}: the stored English, the render in
 * each supported locale, and the primary monitor's name. One `LIKE` over it
 * matches the words on screen whichever language produced them, and folding
 * both sides means Turkish casing (`İstanbul` against `istanbul`) stops being
 * a limitation the query comments apologised for.
 *
 * DELIBERATELY NOT INDEXED. The search is `LIKE '%term%'`, and a leading
 * wildcard cannot use a B-tree on either engine, so an index here would cost
 * writes and buy nothing. Making it fast means a trigram index, which is a
 * PostgreSQL extension SQLite has no answer for, and that is a decision to
 * take when a roster is big enough to need it rather than now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->text('title_search')->nullable();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn('title_search');
        });
    }

    /**
     * Fill the column for rows that predate it.
     *
     * Written through the query builder rather than `$incident->save()` on
     * purpose: saving would move `updated_at` on every historical incident, and
     * on this table that column is read as "when did a responder last touch
     * this". A backfill is not a responder.
     */
    protected function backfill(): void
    {
        Incident::query()
            ->withTrashed()
            ->with('primaryMonitor')
            ->chunkById(500, function ($incidents): void {
                foreach ($incidents as $incident) {
                    DB::table('incidents')
                        ->where('id', $incident->getKey())
                        ->update(['title_search' => Incident::composeSearchText($incident)]);
                }
            });
    }
};
