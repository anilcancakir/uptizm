<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns that let the product prove what it claims and stop paging people
 * for its own limitations.
 *
 * `monitor_checks.colo` is where a probe actually ran. The worker resolved it all
 * along and returned it as a response header, which `RelayClient` discarded, so
 * every stored check's `region` was an echo of the request rather than evidence.
 * A mis-mapped `locationHint` would have produced identical probes under
 * different region labels with nothing able to catch it.
 *
 * `monitors.last_probe_error` records a probe the EDGE refused. Cloudflare's
 * `connect()` rejects a raw TCP connection to any host it serves over HTTP, so a
 * TCP monitor pointed at a proxied hostname failed forever. That was landing as a
 * plain `down`, which opens an incident and pages a responder for a target that is
 * up. It is now kept off the check timeline entirely and surfaced here instead,
 * following the `ssl_last_error` precedent on the same table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table): void {
            // IATA-style colo codes are three characters; 8 leaves room for the
            // literal `unknown` the worker sends when a probe failed before the
            // colo resolved.
            $table->string('colo', 8)->nullable()->after('region');
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('last_probe_error', 255)->nullable()->after('ssl_last_error');
            $table->timestampTz('last_probe_error_at')->nullable()->after('last_probe_error');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table): void {
            $table->dropColumn('colo');
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn(['last_probe_error', 'last_probe_error_at']);
        });
    }
};
