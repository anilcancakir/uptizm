<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `follow_redirects`: whether a monitor's probe follows a 3xx to its
 * destination instead of recording the redirect itself.
 *
 * The probe has always stopped at the redirect, and that is right for a monitor
 * watching one endpoint: a login page answering 302 instead of 200 is a
 * regression, and following it would publish the login screen as health. It is
 * wrong for a homepage behind a geo redirect, where the 3xx IS the service
 * working. Neither answer is correct for every monitor, so it becomes the
 * monitor's own.
 *
 * `false` by default, so no existing monitor changes what it measures. This is
 * an opt-in, not a new default.
 *
 * Column-only, deliberately: the CATALOG probe never follows a redirect
 * whatever this column says, because `resources/legal/bot.en.md` promises every
 * third-party operator that the availability check requests one URL and reads
 * no other page. That promise is kept in `RelayClient::buildSpec()`, at the one
 * place the value reaches the wire, rather than by a database constraint a
 * seeder or a console write could route around.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->boolean('follow_redirects')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('follow_redirects');
        });
    }
};
