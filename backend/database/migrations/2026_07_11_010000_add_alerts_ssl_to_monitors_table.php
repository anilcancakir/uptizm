<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds alert routing + SSL tracking to monitors, and converts `auth_config`
 * from jsonb to text so it can hold an encrypted ciphertext string.
 *
 * A later step puts an `encrypted:array` cast on the model. Laravel serializes
 * that cast to a single opaque ciphertext STRING, which pgsql rejects when the
 * column is jsonb (the ciphertext is not valid JSON). The column must be text.
 *
 * Existing rows are re-encrypted in place: the raw jsonb text is already a JSON
 * string, so encrypting it directly produces exactly what the cast will read.
 * The type change and re-encrypt only apply on pgsql; on sqlite (tests) a jsonb
 * column already has text affinity, so adding the columns is the whole job.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the alert routing + SSL tracking columns (portable across drivers).
        Schema::table('monitors', function (Blueprint $table): void {
            $table->boolean('alert_on_down')->default(true);
            $table->boolean('alert_on_recover')->default(true);
            $table->boolean('ssl_tracking')->default(false);
            $table->timestampTz('ssl_expires_at')->nullable();
            $table->unsignedSmallInteger('ssl_alert_threshold_days')->default(14);
            $table->timestampTz('ssl_last_checked_at')->nullable();
            $table->string('ssl_last_error')->nullable();
        });

        // 2. On pgsql the column must become text before it can hold ciphertext;
        //    jsonb would reject the encrypted string as invalid JSON. sqlite jsonb
        //    already has text affinity, so no type change is needed there.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'ALTER TABLE monitors ALTER COLUMN auth_config TYPE text USING auth_config::text',
            );
        }

        // 3. Re-encrypt existing plaintext auth_config so the later cast reads ciphertext.
        $this->reencryptExistingAuthConfig();
    }

    public function down(): void
    {
        // 1. Decrypt back to plaintext JSON so the value is valid jsonb again.
        $this->decryptExistingAuthConfig();

        // 2. Restore the jsonb type on pgsql; the decrypted plaintext is valid JSON.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'ALTER TABLE monitors ALTER COLUMN auth_config TYPE jsonb USING auth_config::jsonb',
            );
        }

        // 3. Drop the alert routing + SSL tracking columns.
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn([
                'alert_on_down',
                'alert_on_recover',
                'ssl_tracking',
                'ssl_expires_at',
                'ssl_alert_threshold_days',
                'ssl_last_checked_at',
                'ssl_last_error',
            ]);
        });
    }

    /**
     * Rewrite every non-null plaintext auth_config as ciphertext, leaving
     * already-encrypted values untouched (idempotent re-run safety).
     */
    private function reencryptExistingAuthConfig(): void
    {
        DB::table('monitors')
            ->whereNotNull('auth_config')
            ->select([
                'id',
                'auth_config',
            ])
            ->orderBy('id')
            ->each(function (object $monitor): void {
                $raw = (string) $monitor->auth_config;

                try {
                    // Already ciphertext: a successful decrypt means nothing to do.
                    Crypt::decryptString($raw);

                    return;
                } catch (DecryptException) {
                    // Plaintext jsonb text: encrypt it as-is (it is already a JSON string).
                    DB::table('monitors')
                        ->where('id', $monitor->id)
                        ->update([
                            'auth_config' => Crypt::encryptString($raw),
                        ]);
                }
            });
    }

    /**
     * Reverse of the re-encrypt: decrypt every ciphertext auth_config back to
     * its plaintext JSON, leaving already-plaintext values untouched.
     */
    private function decryptExistingAuthConfig(): void
    {
        DB::table('monitors')
            ->whereNotNull('auth_config')
            ->select([
                'id',
                'auth_config',
            ])
            ->orderBy('id')
            ->each(function (object $monitor): void {
                $raw = (string) $monitor->auth_config;

                try {
                    $plaintext = Crypt::decryptString($raw);
                } catch (DecryptException) {
                    // Already plaintext: nothing to reverse.
                    return;
                }

                DB::table('monitors')
                    ->where('id', $monitor->id)
                    ->update([
                        'auth_config' => $plaintext,
                    ]);
            });
    }
};
