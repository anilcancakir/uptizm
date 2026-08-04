<?php

use App\Models\Monitor;
use App\Models\Proxy;
use App\Models\ProxySource;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proxies table backing {@see Proxy}: the individual exits a local probe
 * egresses through, upserted from a {@see ProxySource}'s fetched list.
 *
 * `region` is denormalised from the owning source so region-scoped
 * selection needs no join; the selection predicate itself
 * (region + enabled + reanimation time) is exactly the composite index
 * below, see {@see Proxy::scopeHealthy()}.
 *
 * `credentials` is a single encrypted-at-rest column holding the exit's
 * username and password, following {@see Monitor}'s
 * `auth_config` cast: there is no separate plaintext `username`/`password`
 * pair, because this table holds live provider passwords.
 *
 * `failed_attempts` + `available_at` implement exponential backoff for a
 * penalised exit: a burnt proxy is taken out of rotation until
 * `available_at`, never deleted. `removed_at` is a distinct, later-step
 * sweep marker for an exit that vanished from its source's list entirely; it
 * is NOT Eloquent's soft-delete column and this model does not use the
 * `SoftDeletes` trait, so a removed proxy keeps the record of when it first
 * disappeared without any query scope hiding it implicitly.
 *
 * `(host, port)` is UNIQUE because a later step upserts on that pair as the
 * conflict target; changing it would break that upsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxies', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'proxy_source_id')
                ->constrained('proxy_sources')
                ->cascadeOnDelete();

            $table->string('region', 32);
            $table->string('host', 255);
            $table->unsignedInteger('port');
            $table->text('credentials');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('last_refreshed_at');
            $table->timestampTz('removed_at')->nullable();

            $table->timestamps();

            $table->unique([
                'host',
                'port',
            ]);

            $table->index([
                'region',
                'enabled',
                'available_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxies');
    }
};
