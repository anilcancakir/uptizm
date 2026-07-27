<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the incident postmortem, as `docs/uptizm-system/data-model.md`
 * specifies it: a body plus a publication stamp on the incident itself.
 *
 * Both columns are nullable and independent, and that is the whole contract:
 * `postmortem_body` alone is an INTERNAL draft, and only a non-null
 * `postmortem_published_at` makes it customer-visible on the public status
 * page. The public assembler gates on the stamp, so an unpublished draft can
 * never leak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->text('postmortem_body')->nullable();
            $table->timestampTz('postmortem_published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn([
                'postmortem_body',
                'postmortem_published_at',
            ]);
        });
    }
};
