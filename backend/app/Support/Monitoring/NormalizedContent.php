<?php

namespace App\Support\Monitoring;

/**
 * Immutable pair of content hashes for one response body, plus the outcome of
 * the normalization that produced the second one.
 *
 * The two hashes answer two different questions and must never be swapped:
 *
 * - `rawHash` is the SHA-256 of the bytes that were actually served. It is the
 *   blob ADDRESS, which is what makes a stored file provably contain the
 *   content it is filed under.
 * - `normalizedHash` is the SHA-256 of the same body with token-shaped noise
 *   substituted out. It is the CHANGE SIGNAL, and nothing else.
 *
 * Addressing the blob by the normalized hash is the specific defect this split
 * exists to avoid: two genuinely different pages can normalize to the same
 * string, so a normalized-hash filename would let the archive assert content it
 * never saw.
 */
readonly class NormalizedContent
{
    public function __construct(
        /** SHA-256 hex of the raw decoded body; the archived blob's address. */
        public string $rawHash,

        /** SHA-256 hex of the normalized body; the "did this change" signal. */
        public string $normalizedHash,

        /**
         * The normalization ruleset that produced `normalizedHash`.
         *
         * It travels with the hash because changing a rule changes the signal:
         * a stored hash is only comparable to a new one computed under the same
         * version, so a bump starts a fresh chain instead of reading every
         * archived page as changed exactly once.
         */
        public int $normalizerVersion,

        /**
         * True when a substitution could not run (invalid UTF-8 under the `/u`
         * modifier, or a PCRE limit) and `normalizedHash` therefore covers the
         * RAW bytes.
         *
         * Failing OPEN is deliberate. A failure must read as "changed" and
         * archive; treating it as an empty subject would make `sha256('')` the
         * monitor's permanent change signal, so every later check would read
         * "unchanged" and the archive would stop after one version with nothing
         * in the logs.
         */
        public bool $normalizationFailed,
    ) {}
}
