<?php

namespace App\Support\Monitoring;

/**
 * Shared HMAC-SHA256 signer for the Cloudflare relay worker wire format.
 *
 * The signature covers `"{timestamp}.{body}"` so replays of an old body
 * under a new timestamp (or vice versa) fail verification. Both ends
 * (API + worker) must agree on the exact payload shape; drift here
 * silently breaks every regional check.
 *
 * The timestamp is a plain decimal integer (unix seconds) with no leading
 * zeros or whitespace: the `int` type plus string coercion in {@see sign()}
 * guarantees the exact bytes the CF worker recomputes on its side.
 */
class RelaySigner
{
    /**
     * @param  string  $secret  Shared secret between API and worker.
     * @param  int  $ttlSeconds  Max clock skew / replay window in seconds.
     */
    public function __construct(
        protected string $secret,
        protected int $ttlSeconds = 300,
    ) {}

    /**
     * Produce a deterministic hex HMAC-SHA256 signature for the payload.
     *
     * The signed message is `"{timestamp}.{body}"`; coercing the `int`
     * timestamp yields a plain decimal string, matching the worker's
     * `${timestamp}.${body}` template byte for byte.
     *
     * @param  int  $timestamp  Unix seconds the request was signed at.
     * @param  string  $body  Raw request body being protected.
     * @return string 64-character lowercase hex digest.
     */
    public function sign(int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);
    }

    /**
     * Verify the signature matches and the timestamp is within the TTL window.
     *
     * Uses constant-time comparison to avoid timing side channels.
     *
     * @param  int  $timestamp  Unix seconds carried alongside the request.
     * @param  string  $body  Raw request body being verified.
     * @param  string  $signature  Hex signature presented by the caller.
     */
    public function verify(int $timestamp, string $body, string $signature): bool
    {
        // 1. Reject stale timestamps outside the replay window.
        if (abs(time() - $timestamp) > $this->ttlSeconds) {
            return false;
        }

        // 2. Constant-time compare against the expected signature.
        return hash_equals($this->sign($timestamp, $body), $signature);
    }
}
