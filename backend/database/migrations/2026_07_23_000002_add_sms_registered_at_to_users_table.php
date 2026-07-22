<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publish the magic-starter-laravel `sms_registered_at` column into uptizm's
 * users table. The package migration is undated (package source); uptizm
 * consumes it as a dated copy. Without this column, on-demand OneSignal SMS
 * subscription registration (OneSignalSubscriptions::ensureSmsSubscription)
 * cannot persist its idempotency guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'sms_registered_at')) {
                $table->timestamp('sms_registered_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'sms_registered_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('sms_registered_at');
            });
        }
    }
};
