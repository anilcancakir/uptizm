<?php

namespace App\Support\Monitoring;

/**
 * Filters a probe's raw response headers down to the diagnostic names the
 * monitor-setup prompt actually reads.
 *
 * Fail-closed by NAME, not by value: {@see filter()} builds its result from
 * the allowlist below rather than from the input, so a header this class
 * has never heard of can never survive no matter what the target sends.
 * This boundary matters beyond today's read-only probe: once the
 * auth-metrics plan lands, the probe that produced these headers ran with
 * the customer's own credential, and at that point `Set-Cookie` is an
 * authenticated session token, `Authorization` echoes the very credential
 * that was sent, and `WWW-Authenticate` / `Proxy-Authenticate` can carry a
 * realm or nonce tied to that same session. None of the four is on the
 * list, and neither is anything else unenumerated: a name only earns a
 * place here by having a named consumer in the setup prompt.
 */
class ProbeHeaderAllowList
{
    /**
     * Maximum characters kept per surviving header value, applied AFTER the
     * name filter.
     *
     * 256 mirrors the cap this codebase already applies to an echoed
     * untrusted value (the assertion report's "observed" excerpt,
     * {@see CheckResult::$assertions}), and is generous enough for a
     * WordPress `link` header, which routinely lists several `rel=`
     * entries, while keeping the handful of headers a real probe carries
     * at once well inside the 500-character budget
     * `AnalysisPayload::UNTRUSTED_FIELD_MAX_LENGTH` applies to the whole
     * encoded headers value further down the pipeline.
     */
    public const int VALUE_MAX_LENGTH = 256;

    /**
     * The closed list of header names the setup prompt reads, and why.
     *
     * This ORDER is also the return order of {@see filter()}: a hostile
     * target cannot influence prompt ordering because `filter()` iterates
     * this list, never the input.
     *
     * - Body shape and locale, feeding the response digest's shape and the
     *   region suggestion's `content_language` basis: `content-type`,
     *   `content-length`, `content-encoding`, `content-language`.
     * - Framework fingerprint, feeding `service_class`: `server`,
     *   `x-powered-by`, `x-generator`, `x-aspnet-version`, `x-runtime`.
     * - Cache posture, explaining a suspiciously fast or stale reading:
     *   `via`, `age`, `cache-control`, `x-cache`, `x-cache-status`,
     *   `x-litespeed-cache`, `cf-cache-status`, `x-drupal-cache`.
     * - CDN POP, which the planned `TargetLocation` service reads to decide
     *   `cdn_edge`: `cf-ray`, `x-amz-cf-pop`, `x-served-by`.
     * - A correlation id an operator can quote to their own provider:
     *   `x-request-id`.
     * - WordPress advertises its REST root here, a real fingerprint: `link`.
     *
     * Deliberately absent: `strict-transport-security` and
     * `x-frame-options` describe security posture, and nothing in the
     * setup prompt consumes either. `set-cookie`, `authorization`,
     * `www-authenticate` and `proxy-authenticate` are credential-bearing
     * once a probe runs with the customer's own credential and can NEVER be
     * added, however diagnostic they might look; any other name is dropped
     * for the same reason it was never enumerated in the first place:
     * nobody has named a prompt consumer for it.
     *
     * @var list<string>
     */
    private const array ALLOWED_NAMES = [
        'content-type',
        'content-length',
        'content-encoding',
        'content-language',
        'server',
        'x-powered-by',
        'x-generator',
        'x-aspnet-version',
        'x-runtime',
        'via',
        'age',
        'cache-control',
        'x-cache',
        'x-cache-status',
        'x-litespeed-cache',
        'cf-cache-status',
        'x-drupal-cache',
        'cf-ray',
        'x-amz-cf-pop',
        'x-served-by',
        'x-request-id',
        'link',
    ];

    /**
     * Reduce a raw response-header map to the diagnostic names the setup
     * prompt consumes, case-insensitively, each surviving value capped at
     * {@see VALUE_MAX_LENGTH}.
     *
     * @param  array<string, string>  $headers  Raw response headers, any name casing.
     * @return array<string, string> Kept headers: lowercase names, allowlist order, capped values.
     */
    public static function filter(array $headers): array
    {
        // 1. Lowercase the incoming names once, so the allowlist walk below
        //    is a single case-insensitive lookup rather than a comparison
        //    per allowlist entry.
        $lowercased = [];
        foreach ($headers as $name => $value) {
            $lowercased[strtolower((string) $name)] = $value;
        }

        // 2. Walk the ALLOWLIST, not the input. A name the target sent that
        //    is not in `ALLOWED_NAMES` is never even considered, and the
        //    result inherits the allowlist's own order.
        $kept = [];
        foreach (self::ALLOWED_NAMES as $name) {
            if (! array_key_exists($name, $lowercased)) {
                continue;
            }

            $value = self::flatten($lowercased[$name]);

            if ($value === null) {
                continue;
            }

            $kept[$name] = mb_substr($value, 0, self::VALUE_MAX_LENGTH);
        }

        return $kept;
    }

    /**
     * One header value as a string, or null when there is nothing usable.
     *
     * Type-checked rather than cast, because a `(string)` cast on an array
     * raises a warning Laravel rethrows as an `ErrorException`, and this runs
     * inside a request whose whole point is that it degrades rather than throws.
     *
     * A LIST is joined rather than dropped, and `link` is why: WordPress
     * advertises its REST root there, a page often sends several `rel=` entries,
     * and the constant above earns that name its place precisely for that
     * fingerprint. Today's worker folds duplicates into one string before we see
     * them, so this branch is defensive; dropping the value instead would have
     * meant the docblock naming a consumer for evidence that could silently
     * never arrive. Anything else (an object, a bool, a nested array) is not a
     * header value and is dropped.
     */
    private static function flatten(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $strings = array_filter($value, static fn (mixed $item): bool => is_string($item) || is_int($item) || is_float($item));

        return $strings === [] ? null : implode(', ', array_map(static fn (mixed $item): string => (string) $item, $strings));
    }
}
