<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether Uptizm may write and publish this monitor's incident status updates
 * without being asked.
 *
 * Its own column rather than another rung on `ai_mode`, because the two are
 * different consents and the useful combinations cross them. `ai_mode` answers
 * "may you decide there is an incident?"; this answers "may you speak to my
 * customers about one?". Folding the second into the third rung of the first
 * forced an operator who only wanted their outages narrated to also accept
 * autonomous incident creation, and it withheld narration from the most common
 * incident there is: the one a threshold opened.
 *
 * Default false. Publishing to a public status page with nobody in the loop is
 * never something a monitor should arrive already doing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->boolean('ai_auto_updates')->default(false)->after('ai_mode');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('ai_auto_updates');
        });
    }
};
