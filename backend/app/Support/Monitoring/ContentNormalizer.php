<?php

namespace App\Support\Monitoring;

/**
 * Hashes a response body twice, once raw and once with token-shaped noise
 * substituted out, so an unchanged page stops looking changed.
 *
 * This is measured, not theoretical. Three sequential fetches of the one page
 * this product actually monitors produced three different raw hashes differing
 * only in a single 40-character CSRF token that appears twice in a 182 KB
 * document. Raw-byte hashing therefore dedupes 0% of an unchanged page, which
 * would archive a fresh 182 KB blob on every check and defeat the whole point
 * of the archive. The two substitutions below took that to 100% while touching
 * exactly two attribute values on that page.
 *
 * The rules stay narrow and anchored on purpose, and that is the hard part of
 * this class: normalization trades a false-positive problem for a false-negative
 * one, and a rule broad enough to erase a token can erase a real edit. Stripping
 * tags, extracting text or collapsing whitespace would dedupe harder, hide real
 * changes, and destroy the markup structure the metric-candidate extractor
 * reads off the same body.
 */
class ContentNormalizer
{
    /**
     * Stands in for a substituted token value; only its stability matters.
     */
    protected const string TOKEN_PLACEHOLDER = '<TOKEN>';

    /**
     * The anchored substitutions, applied in order, each replacing group 1's
     * attribute prefix plus a placeholder value.
     *
     * Rule 1 covers an attribute whose NAME carries a token word (`data-csrf`,
     * `data-nonce`, `X-CSRF-Token`) with a value of 16+ characters; the floor
     * keeps ordinary short attribute values out. Rule 2 covers
     * `content="<base64/hex-ish 32+ chars>"`, which is the `<meta
     * name="csrf-token">` shape where the token word sits in a DIFFERENT
     * attribute than the value, so rule 1 structurally cannot reach it. The
     * character class excludes spaces, which is what keeps prose meta
     * descriptions out.
     *
     * `/u` is required: it is what makes an invalid-UTF-8 body a detected
     * failure instead of a silent mis-substitution. Neither pattern nests a
     * quantifier inside a quantifier, so a hostile 1 MB body cannot backtrack
     * catastrophically (each is a single greedy class PCRE auto-possessifies
     * against the following delimiter).
     */
    protected const array TOKEN_PATTERNS = [
        '/((?:csrf|token|nonce|_token)[^\s=]*=")[^"]{16,}"/iu',
        '/(content=")[A-Za-z0-9+\/=_-]{32,}"/u',
    ];

    /**
     * Hash a decoded response body raw and normalized.
     *
     * @param  string  $body  The full DECODED response body. Hashing encoded
     *                        bytes would measure the compressor, not the page.
     * @return NormalizedContent Both hashes, the ruleset version, and whether
     *                           normalization fell back to the raw bytes.
     */
    public static function normalize(string $body): NormalizedContent
    {
        // 1. Address the bytes that were served, before anything touches them.
        $rawHash = hash('sha256', $body);

        // 2. Substitute token-shaped noise, one anchored rule at a time.
        $subject = $body;
        $failed = false;

        foreach (self::TOKEN_PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, '${1}'.self::TOKEN_PLACEHOLDER.'"', $subject);

            // `preg_replace` returns null on invalid UTF-8 under `/u` and on a
            // PCRE backtrack/JIT limit, and a chain of them folds toward the
            // empty string. Checking after EVERY substitution is what keeps
            // `sha256('')` from becoming a monitor's permanent change signal,
            // which would read "unchanged" forever and silently stop the
            // archive after one version.
            if ($replaced === null || preg_last_error() !== PREG_NO_ERROR) {
                $failed = true;

                break;
            }

            $subject = $replaced;
        }

        // 3. Fail OPEN: an unusable normalization hashes the RAW bytes, so the
        //    check reads as changed and gets archived. Failing closed (an empty
        //    or partially substituted subject) would read as unchanged and lose
        //    the content with nothing to notice it by.
        $normalizedSubject = $failed ? $body : $subject;

        return new NormalizedContent(
            rawHash: $rawHash,
            normalizedHash: hash('sha256', $normalizedSubject),
            normalizerVersion: (int) config('content-archive.normalizer_version'),
            normalizationFailed: $failed,
        );
    }
}
