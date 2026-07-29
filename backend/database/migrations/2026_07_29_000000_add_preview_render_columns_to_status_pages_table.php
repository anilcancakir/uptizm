<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds the headless preview-render bookkeeping to `status_pages` and backfills
 * the `preview_token` every existing row is missing.
 *
 * All three columns are nullable, are written by the renderer and its job, and
 * are never mass assignable: `preview_image_path` is the key of the stored PNG
 * on the private `local` disk, `preview_rendered_at` is the moment the artefact
 * was captured (the client labels the image with it, so it carries the honesty
 * contract, not just bookkeeping), and `preview_render_status` carries
 * App\Enums\StatusPagePreviewStatus. There is deliberately no column default
 * and no `pending` state: NULL alone means "no render yet".
 *
 * The backfill is the load-bearing half. `preview_token` has been nullable
 * since the table was created and nothing generated it, so every existing row
 * holds NULL, and the public controller fails closed on an empty stored token.
 * A private page was therefore unreadable by anything at all, the renderer
 * included. `down()` deliberately does NOT undo the backfill: a rollback should
 * drop the columns it added, not revoke access tokens it cannot tell apart from
 * deliberately assigned ones.
 *
 * No key is added here, so `MigrationHelper` is not involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_pages', function (Blueprint $table): void {
            $table->string('preview_image_path')->nullable();
            $table->timestampTz('preview_rendered_at')->nullable();
            $table->string('preview_render_status', 16)->nullable();
        });

        $this->backfillMissingPreviewTokens();
    }

    public function down(): void
    {
        Schema::table('status_pages', function (Blueprint $table): void {
            $table->dropColumn([
                'preview_image_path',
                'preview_rendered_at',
                'preview_render_status',
            ]);
        });
    }

    /**
     * Give every tokenless status page a token, one distinct value per row.
     *
     * An empty string counts as missing: the controller's fail-closed guard
     * treats it exactly like NULL. Kept public and separate from `up()` so the
     * data half is testable without re-running the schema half.
     */
    public function backfillMissingPreviewTokens(): void
    {
        $ids = DB::table('status_pages')
            ->whereNull('preview_token')
            ->orWhere('preview_token', '')
            ->pluck('id');

        foreach ($ids as $id) {
            DB::table('status_pages')
                ->where('id', $id)
                ->update(['preview_token' => Str::random(40)]);
        }
    }
};
