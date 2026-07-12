<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Processed webhook events table: the idempotency backbone for re-delivered
 * webhooks (Stripe today; reused later for store `transaction_id`).
 *
 * The unique `event_id` is the load-bearing dedup key: insert-then-handle
 * means a webhook handler tries to insert the event first, and a unique
 * constraint violation means the event was already processed, so the
 * re-delivery is a total no-op. `type` records the event kind for
 * observability; `processed_at` is when the row was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_webhook_events', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            $table->string('event_id')->unique();
            $table->string('type');
            $table->timestampTz('processed_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_webhook_events');
    }
};
