<?php

use App\Models\ProxySource;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxy sources table backing {@see ProxySource}: the per-region provider a
 * local probe's exit list is fetched from, mirroring `config/proxy.php`'s
 * `sources` map into a row a later refresh job can update
 * `last_refreshed_at`/`last_error` on.
 *
 * `region` is UNIQUE because this design allows at most one source per
 * region (see the config file's docblock); a second source for the same
 * region would leave no rule for which one a refresh job should trust.
 *
 * `kind` distinguishes a fetched `url` list from a static `file` list, the
 * same two kinds `config/proxy.php` declares. `last_error` records the most
 * recent fetch failure so an operator panel (a later step) can surface why a
 * region's pool went stale without a source ever being deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_sources', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);

            $table->string('region', 32)->unique();
            $table->string('kind', 8);
            $table->text('location');
            $table->timestampTz('last_refreshed_at')->nullable();
            $table->string('last_error', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_sources');
    }
};
