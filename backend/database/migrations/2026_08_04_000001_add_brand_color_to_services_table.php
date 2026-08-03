<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The accent colour a service's header tile is drawn in.
 *
 * A COLUMN RATHER THAN A CONSTANT, because it is a per-service editorial value and
 * the staff panel is where editorial values are set. `ServiceCatalogSeeder` fills it
 * for the services whose colour has a citable source (the CC0 `simple-icons`
 * dataset), and leaves it null for the rest, where the tile falls back to the
 * product's own brand pair.
 *
 * NULLABLE is the load-bearing part. Two of the eight seeded services have no colour
 * in that dataset, because `simple-icons` removes a brand when its owner asks, and
 * typing a trademark colour from memory is exactly the kind of unsourced claim this
 * surface refuses everywhere else. Null means "we have no source for this", not
 * "black".
 *
 * Stored as the 7-character `#rrggbb` literal the tile renders inline, validated on
 * the way in by `ServiceForm`. It is TENANT-STYLE DATA, not a design token: it cannot
 * flip with the reader's colour scheme, which is why the customer status page's
 * equivalent (`status/partials/brand-header.blade.php`) pins a fixed white
 * foreground over it rather than a token that would invert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'brand_color')) {
                $table->string('brand_color', 7)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('services', 'brand_color')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->dropColumn('brand_color');
            });
        }
    }
};
