<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status page translations table: a machine-translated value for one row's
 * one field in one language, never a translation of a bare string.
 *
 * The unique key is the morph pair plus `field` plus `locale`, NOT a content
 * hash: status-page boilerplate ("Investigating elevated error rates.")
 * collides constantly across tenants, and keying by hash would let one
 * tenant's translation surface under another tenant's brand. `team_id` rides
 * along as a denormalised guard so a team-scoped read never needs a join to
 * the translatable row, but it is deliberately outside the unique index
 * because the morph pair already identifies the row uniquely.
 *
 * `source_hash` exists only to detect that the SOURCE text moved under a
 * stored translation (the operator edited an update after it was
 * translated); it is never a lookup key. `value` is nullable and a non-null
 * `rejected_at` is the recorded rejection: the suspect text itself is never
 * stored, only the fact that it was rejected, which is what lets the page
 * distinguish "no translation yet" from "translation unavailable" without
 * re-queueing the same string forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_translations', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();
            MigrationHelper::morphColumns($table, 'translatable');

            $table->string('field', 40);
            $table->string('locale', 5);
            $table->text('value')->nullable();
            $table->string('source_hash', 64);
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 64)->nullable();

            $table->timestamps();

            $table->index('team_id');
            $table->unique([
                'translatable_type',
                'translatable_id',
                'field',
                'locale',
            ], 'status_page_translations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_translations');
    }
};
