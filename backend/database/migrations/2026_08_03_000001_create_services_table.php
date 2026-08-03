<?php

use App\Enums\ServiceStatusSource;
use App\Models\Service;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Services table: the uptizm-owned catalog of third-party services this
 * platform monitors and publishes a public SEO page for.
 *
 * `status_source` records which official feed (if any) enriches the page
 * (see {@see ServiceStatusSource}); `status_source_url` is the
 * upstream feed endpoint, validated through the shared SSRF guard the same
 * way a monitor target is (see
 * {@see Service::assertStatusSourceUrlAllowed()}).
 *
 * `content_changed_at` is deliberately distinct from `updated_at`: a later
 * step's ingestion job writes it ONLY when the normalized feed hash actually
 * changes, and a later step's sitemap derives its `lastmod` from it. A
 * routine poll that only refreshes `updated_at` must never move the public
 * `lastmod`, because Google discounts an untrustworthy `lastmod` sitewide.
 * Without a column distinct from `updated_at` there is no way to tell "the
 * page changed" from "we merely re-checked it".
 *
 * `terms_reviewed_at` + `terms_note` record the legal review a service must
 * pass before publication (see {@see Service::canPublish()});
 * `feed_disabled_at` + `feed_disabled_reason` record why a later ingestion
 * step stopped polling a provider (a 429 or 403 response disables it
 * automatically per this plan's Must Have).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            $table->string('slug')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('status_source', 32)->default('none');
            $table->string('status_source_url', 2048)->nullable();
            $table->string('terms_url', 2048)->nullable();
            $table->timestampTz('terms_reviewed_at')->nullable();
            $table->text('terms_note')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('feed_disabled_at')->nullable();
            $table->string('feed_disabled_reason')->nullable();
            $table->timestampTz('content_changed_at')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);

            $table->timestamps();

            $table->index([
                'is_published',
                'display_order',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
