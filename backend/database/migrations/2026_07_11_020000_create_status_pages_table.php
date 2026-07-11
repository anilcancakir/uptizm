<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status pages table: the core entity for a team's public status page.
 *
 * One row configures a single public page (branding, visibility, custom
 * domain routing, subscription opt-in). `slug` is globally unique because it
 * addresses the page on the shared path-based host; `is_public` and
 * `subscriptions_enabled` default false so a page is fail-closed until a team
 * deliberately publishes it. `preview_token` gates unlisted preview access
 * while a page is still private.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();

            // Identity + addressing.
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain_mode', 16)->default('path');
            $table->string('custom_domain')->nullable();

            // Branding.
            $table->string('brand_color', 9)->default('#008560');
            $table->string('logo_path')->nullable();
            $table->string('logo_text', 8)->nullable();
            $table->string('description')->nullable();

            // Visibility + subscriptions (fail-closed: both default false).
            $table->boolean('is_public')->default(false);
            $table->boolean('subscriptions_enabled')->default(false);
            $table->string('preview_token', 64)->nullable();

            $table->timestamps();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_pages');
    }
};
