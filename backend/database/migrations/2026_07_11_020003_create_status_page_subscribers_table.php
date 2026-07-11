<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status-page subscribers: visitors who opted in to incident notifications
 * for a public status page. `confirmed_token`/`unsubscribe_token` are opaque
 * random strings (not secrets) used to confirm a subscription or unsubscribe
 * via a signed link, so both are indexed for fast lookup. `subscribed_at` and
 * `confirmed_at` are nullable timestamps because a subscriber may register
 * before confirming (double opt-in). The `(status_page_id, email)` unique
 * constraint keeps one subscription per email per page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_subscribers', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'status_page_id')
                ->constrained('status_pages')
                ->cascadeOnDelete();

            $table->string('email');
            // Nullable so confirmation can BURN the token (single-use): once a
            // subscriber confirms, `confirmed_token` is set to null, and the
            // by-token lookup never matches a null row, so a link-prefetch or
            // scanner replay of the confirm URL is a no-op 404.
            $table->string('confirmed_token', 64)->nullable();
            $table->string('unsubscribe_token', 64);
            $table->timestampTz('subscribed_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->boolean('newsletter_opt_in')->default(false);

            $table->timestamps();

            $table->unique([
                'status_page_id',
                'email',
            ]);
            $table->index('unsubscribe_token');
            $table->index('confirmed_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_subscribers');
    }
};
