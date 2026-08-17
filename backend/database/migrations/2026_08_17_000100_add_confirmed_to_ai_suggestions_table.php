<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the model's own verdict on the anomaly it labeled.
 *
 * The triage schema has always asked for `confirmed` (does the evidence read as
 * a real deviation), and the gateway has always validated it, but nothing ever
 * stored it: a model that answered "this is not real" produced a suggestion
 * indistinguishable from one it stood behind.
 *
 * NULLABLE means "no model verdict", which is a real and common state rather
 * than missing data: the statistical degrade path (over budget, or a gateway
 * failure) writes a suggestion with no model involved at all, and every row
 * predating this column was written before the answer was kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_suggestions', function (Blueprint $table): void {
            $table->boolean('confirmed')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_suggestions', function (Blueprint $table): void {
            $table->dropColumn('confirmed');
        });
    }
};
