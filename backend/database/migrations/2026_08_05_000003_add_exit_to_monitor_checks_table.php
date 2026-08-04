<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the local engine's proxy exit the same standing `colo` has.
 *
 * `colo` is where a WORKER-produced check actually ran; it is `string(8)`
 * because it only ever holds a three-letter IATA code, which is too narrow for
 * a `host:port` pair and is not extended here for that reason. A
 * LOCALLY-produced check has no Cloudflare colo to report, but it does have a
 * proxy exit, and that exit is the same kind of evidence: `region` is an echo
 * of what the pool was asked for, so without the exit a blocked or misbehaving
 * proxy would look identical to every other reading from that region and an
 * operator would have no way to tell one bad exit from a genuinely dead
 * region.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table): void {
            $table->string('exit_via', 64)->nullable()->after('colo');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table): void {
            $table->dropColumn('exit_via');
        });
    }
};
