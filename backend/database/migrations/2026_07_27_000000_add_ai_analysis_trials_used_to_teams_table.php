<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meter the Free tier's AI monitor setups.
 *
 * AI monitor analysis is open on Free for a fixed number of successful setups
 * (`config('plans.tiers.*.limits.ai_analysis_trials')`), so the count has to
 * survive restarts and be per team rather than per session. Paid tiers entitle
 * the feature outright and never touch this counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            if (! Schema::hasColumn('teams', 'ai_analysis_trials_used')) {
                $table->unsignedSmallInteger('ai_analysis_trials_used')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('teams', 'ai_analysis_trials_used')) {
            Schema::table('teams', function (Blueprint $table): void {
                $table->dropColumn('ai_analysis_trials_used');
            });
        }
    }
};
