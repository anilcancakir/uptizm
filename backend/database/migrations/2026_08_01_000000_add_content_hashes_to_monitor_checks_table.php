<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two content hashes a check carries once its body has been archived.
 *
 * The names read as a symmetric pair and are not. Every later stage depends on
 * the asymmetry, so it is documented here rather than in one service:
 *
 *   - `content_hash_normalized` is the SHA-256 of THIS check's own normalized
 *     body. It is the CHANGE SIGNAL: matching the monitor's previous value means
 *     the page did not meaningfully change, so nothing new is archived.
 *   - `content_hash` is the ADDRESS of the archived version this check's content
 *     resolved to, and the stem of that version's blob filename. On an unchanged
 *     check it is an EARLIER body's raw hash rather than this body's, because the
 *     stored bytes are the ones that were served the first time this normalized
 *     content appeared. Hashing this check's own raw bytes and expecting to find
 *     the result in this column is therefore wrong.
 *
 * Two hashes rather than one is deliberate: the raw hash names the file, so the
 * blob provably contains bytes that were really served, while the normalized hash
 * decides whether anything changed.
 *
 * Both nullable: a TCP probe has no body, a response outside the content-type
 * allowlist never crosses the wire, and every row written before this feature has
 * neither hash.
 *
 * The columns sit beside `response_body_preview`, which stays exactly as it is
 * (10 KiB, with its PostgreSQL CHECK constraint) so existing readers of the
 * preview keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table): void {
            // 64 characters because SHA-256 hex is exactly that long, always.
            $table->string('content_hash', 64)
                ->nullable()
                ->after('response_body_preview');
            $table->string('content_hash_normalized', 64)
                ->nullable()
                ->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_checks', function (Blueprint $table): void {
            $table->dropColumn([
                'content_hash',
                'content_hash_normalized',
            ]);
        });
    }
};
