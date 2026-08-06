<?php

namespace App\Support\Monitoring;

use App\Services\Monitoring\ThresholdEvaluator;

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
 *
 * ## The JSON half, and why it needs its own rules
 *
 * The HTML rules above dedupe an HTML page to 100%. They dedupe a JSON health
 * endpoint to 0%, because nothing in one looks like `attr="token"`. Measured on
 * the one such endpoint this product monitors: 627 archived versions, 129.5 a
 * day, one per check, every one of them a fresh blob written to a remote mount.
 * Nine of roughly forty-three overnight writes then died on a transient stall,
 * permanently, because the write does not retry.
 *
 * What churns in that document is measurements: `duration_ms`, `latency_ms`,
 * `used_memory_mb`, `connected_clients`, `age_seconds`, and two clock strings.
 * What an operator actually reads it for is the STATE: `"status": "ok"`,
 * `"maintenance": false`, and the shape of the tree itself.
 *
 * So the JSON rules replace every numeric leaf and every ISO-8601 datetime
 * string, and keep everything else: keys, other strings, booleans, nulls, and
 * the structure. A status flipping, a check appearing or disappearing, a deploy
 * changing a commit sha, all still change the hash.
 *
 * **The cost, stated rather than buried:** a purely NUMERIC state change stops
 * marking the content as changed. A queue going from `pending: 0` to
 * `pending: 5000` no longer archives a version. That is a real loss and it is
 * accepted here for a specific reason: numeric thresholds are what custom
 * metrics are for, and those evaluate the LIVE body at check time through
 * {@see ThresholdEvaluator}, never the archive. The
 * archive answers "what did this endpoint look like, and when did its shape or
 * state change", not "what was the number".
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
    /**
     * Max nesting `json_decode` will accept. Deeper than any status document and
     * shallow enough that a hostile body cannot exhaust the stack.
     */
    protected const int JSON_MAX_DEPTH = 64;

    /**
     * An ISO-8601 datetime: a date, a `T` or space separator, a time, and an
     * optional zone. The time part is required, so a plain calendar date is left
     * alone.
     */
    protected const string ISO8601_PATTERN = '/^\\d{4}-\\d{2}-\\d{2}[T ]\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+-]\\d{2}:?\\d{2})?$/';

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

        // 2. A JSON body takes the JSON rules and nothing else: the HTML
        //    substitutions cannot match it, and running them would only spend
        //    time proving that.
        $json = self::normalizeJson($body);

        if ($json !== null) {
            return new NormalizedContent(
                rawHash: $rawHash,
                normalizedHash: hash('sha256', $json),
                normalizerVersion: (int) config('content-archive.normalizer_version'),
                normalizationFailed: false,
            );
        }

        // 3. Substitute token-shaped noise, one anchored rule at a time.
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

    /**
     * Re-encode [$body] with every measurement erased, or `null` when it is not
     * a JSON object or array.
     *
     * Returns `null` rather than throwing for anything it cannot handle, so the
     * caller falls through to the HTML rules: a page is not a failure.
     *
     * A bare JSON scalar (`123`, `"ok"`, `true`) is deliberately NOT handled.
     * Normalizing one would collapse every numeric body in the product to a
     * single hash, so an endpoint that answers a bare number would look
     * unchanged forever.
     */
    protected static function normalizeJson(string $body): ?string
    {
        $decoded = json_decode($body, true, self::JSON_MAX_DEPTH);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $encoded = json_encode(
            self::eraseMeasurements($decoded),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $encoded === false ? null : $encoded;
    }

    /**
     * Walk [$value], replacing every numeric leaf and every ISO-8601 datetime
     * string with the placeholder, and leaving keys and structure alone.
     *
     * Booleans and nulls survive: `"maintenance": false` flipping to `true` is
     * exactly the kind of change the archive exists to catch.
     */
    protected static function eraseMeasurements(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::eraseMeasurements($item), $value);
        }

        if (is_int($value) || is_float($value)) {
            return self::TOKEN_PLACEHOLDER;
        }

        if (is_string($value) && self::looksLikeTimestamp($value)) {
            return self::TOKEN_PLACEHOLDER;
        }

        return $value;
    }

    /**
     * Whether [$value] is an ISO-8601 datetime, the one string shape that churns
     * on every sample of a status document (`checked_at`, `deployed_at`).
     *
     * Anchored on the shape rather than on the key name, because the key varies
     * per endpoint and the shape does not. A date with no time (`2026-08-05`) is
     * NOT matched: that is a value that changes once a day, which is a real
     * change worth archiving.
     */
    protected static function looksLikeTimestamp(string $value): bool
    {
        return preg_match(self::ISO8601_PATTERN, $value) === 1;
    }
}
