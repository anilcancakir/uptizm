<?php

namespace App\Support\Monitoring;

use App\Enums\HttpAuthType;
use App\Http\Requests\Concerns\ValidatesAuthConfig;
use App\Http\Resources\MonitorResource;

/**
 * Replaces every occurrence of the operator's own credential in
 * probe-controlled text with a fixed marker.
 *
 * It matches the WIRE forms, not the submitted ones, and that distinction is
 * the whole point of the class. The relay worker turns `auth_config` into a
 * request header before it probes (`authHeaders()`,
 * `backend/workers/regional-checker/src/regional-probe.ts:1105-1135`), so a
 * basic credential leaves as `Basic base64("user:pass")` and never as the pair
 * itself. A debug page, a request-echo endpoint or a verbose error page prints
 * THAT string, which means a redactor built from the submitted `username` and
 * `password` finds nothing in the single most likely echo case while looking
 * entirely correct in every test that plants the plaintext.
 *
 * The match set is therefore built from what the worker actually sends, per
 * type, plus the shapes a target can echo it in without changing its bytes:
 * the `rawurlencode` form, and the first {@see HEAD_PREFIX_LENGTH} characters
 * for a head-truncated echo. Needles are matched longest first, so a long form
 * is consumed before any shorter form that is a substring of it; the reverse
 * order would replace the head of a token in place and leave its tail on the
 * page.
 *
 * This class knows nothing about prompts, digests or metrics. It takes text
 * and returns text, and the single caller decides where the seam sits. That is
 * deliberate: a credential-aware payload class would be one control per
 * renderer, and there are two renderers today.
 *
 * WHAT IT DOES NOT COVER, and cannot:
 *
 * - A MID-VALUE cut. A head truncation is covered by the prefix entries; a
 *   target that prints the middle or the tail of a credential is not, because
 *   the resulting substring was never a wire form.
 * - A DECODED echo. A JWT's payload rendered as claims, or a basic pair the
 *   target base64-DEcoded before printing back, shares no bytes with anything
 *   sent.
 * - A DERIVED echo. `sha256(token)` inside an error id, a length, a fingerprint.
 *
 * None of the three is matchable by any value-based redactor, so this docblock
 * states them rather than the class pretending to a coverage it does not have.
 * The residual is bounded by what a target chooses to echo, which is not
 * something this codebase controls.
 */
readonly class CredentialRedactor
{
    /**
     * What a matched credential becomes.
     *
     * A visible marker rather than an empty string, because a model reads the
     * result: an empty header value reads as a target that sent one, while
     * this reads as evidence deliberately withheld.
     */
    public const string MARKER = '[redacted]';

    /**
     * Shortest form that earns a place in the match set.
     *
     * Below four characters a value match is noise rather than a control. A
     * three-character password occurs by chance in ordinary prose, in a
     * timestamp, in a hex id, so replacing every occurrence of it would shred
     * the digest the analysis is built from while protecting nothing: a secret
     * that short is reachable by far cheaper means than reading an echo. So a
     * three-character password is skipped entirely, and the plan records the
     * consequence as an accepted residual rather than hiding it here. Note
     * that skipping the password does NOT skip the type: the basic wire form
     * `base64("user:pas")` is long enough and stays in the set, which is the
     * form that would actually be echoed.
     *
     * FOUR here and EIGHT in the plan's Risks Accepted are two different
     * statements, and reading one as the other is the obvious mistake. Four is
     * a hard floor: below it a value is never added as a needle. Eight is where
     * the protection stops being MEANINGFUL: a five-to-seven character secret
     * IS added and IS replaced, but at that length a value match either misses
     * the form the target actually echoed or matches so widely that the digest
     * is destroyed, so no guarantee is claimed for it either way. Raising this
     * constant to eight would not close that gap, it would widen it, by
     * dropping those needles entirely.
     */
    public const int MIN_MATCH_LENGTH = 4;

    /**
     * How much of a wire form is matched on its own, for a truncated echo.
     *
     * Sixteen characters is long enough that a match is the credential rather
     * than a coincidence, and short enough to catch the common rendering of a
     * long bearer token as `Bearer eyJhbGciOi` followed by an ellipsis.
     */
    public const int HEAD_PREFIX_LENGTH = 16;

    /**
     * @param  list<string>  $needles  Wire forms to replace, longest first.
     */
    private function __construct(private array $needles) {}

    /**
     * Build a redactor for one submitted credential map.
     *
     * Returns a no-op instance for null, for `type: none` and for a type this
     * backend does not know, so a caller never needs a conditional around the
     * redaction: the seam runs unconditionally and an unauthenticated probe
     * pays one `str_replace` over an empty needle list.
     *
     * @param  array<string, mixed>  $authConfig  A validated `auth_config` map
     *                                            ({@see ValidatesAuthConfig}),
     *                                            or null when the probe carries no credential.
     */
    public static function for(?array $authConfig): self
    {
        if ($authConfig === null) {
            return new self([]);
        }

        $submittedType = $authConfig['type'] ?? null;
        $type = is_string($submittedType) ? HttpAuthType::tryFrom($submittedType) : null;

        if ($type === null) {
            return new self([]);
        }

        return new self(self::matchSet($type, $authConfig));
    }

    /**
     * Replace every wire form of the credential in one string.
     *
     * Null passes through, so the caller can hand over an optional body
     * preview or error message without unwrapping it first.
     */
    public function redact(?string $text): ?string
    {
        if ($text === null || $this->needles === []) {
            return $text;
        }

        // `str_replace` with an array applies the needles IN ORDER, each to the
        // result of the previous, which is what makes the longest-first sort in
        // `matchSet()` load-bearing rather than cosmetic. It is also a plain
        // string replace and not a pattern, so the `+`, `/` and `=` a base64
        // wire form carries are literal bytes here and need no quoting.
        return str_replace($this->needles, self::MARKER, $text);
    }

    /**
     * Redact the VALUES of a map, recursing into nested arrays.
     *
     * Keys are left alone on purpose. A header name is not a secret, and the
     * api_key scheme's configured header name is already published by
     * {@see MonitorResource}, so redacting it would remove a diagnostic the
     * setup prompt legitimately reads while hiding nothing.
     *
     * Non-string leaves are returned untouched rather than cast: a raw header
     * map genuinely carries ints and lists, and a `(string)` cast on an array
     * raises the warning Laravel rethrows as an `ErrorException`, in a request
     * path whose contract is that it degrades rather than throws.
     *
     * @param  array<array-key, mixed>  $map
     * @return array<array-key, mixed>
     */
    public function redactMap(array $map): array
    {
        foreach ($map as $key => $value) {
            if (is_string($value)) {
                $map[$key] = $this->redact($value);

                continue;
            }

            if (is_array($value)) {
                $map[$key] = $this->redactMap($value);
            }
        }

        return $map;
    }

    /**
     * The needles for one credential map: wire forms, expanded, filtered, sorted.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function matchSet(HttpAuthType $type, array $config): array
    {
        // 1. The forms the worker actually puts on the wire for this type. The
        //    match is exhaustive with no default so a fifth auth type cannot be
        //    added to the enum and silently redact nothing.
        $wireForms = match ($type) {
            HttpAuthType::None => [],
            HttpAuthType::Basic => self::basicForms($config),
            HttpAuthType::Bearer => self::bearerForms($config),
            HttpAuthType::ApiKey => self::apiKeyForms($config),
        };

        // 2. Two more shapes per form, both of which preserve the bytes: the
        //    percent-encoded copy a page prints when it reflects the value into
        //    a URL, and the head a page prints when it truncates.
        $expanded = [];
        foreach ($wireForms as $form) {
            $expanded[] = $form;
            $expanded[] = rawurlencode($form);
            $expanded[] = mb_substr($form, 0, self::HEAD_PREFIX_LENGTH);
        }

        // 3. Drop what is too short to be a signal, then deduplicate: an
        //    alphanumeric value equals its own `rawurlencode` form, and a value
        //    under the prefix length equals its own head, so the same needle
        //    would otherwise be replaced two or three times over.
        $needles = array_values(array_unique(array_filter(
            $expanded,
            static fn (string $form): bool => mb_strlen($form) >= self::MIN_MATCH_LENGTH,
        )));

        // 4. Longest first. A token and its own 16-character head can both be
        //    on one page (the full value in a header echo, the truncated one in
        //    a log line beneath it); replacing the head first would eat the
        //    front of the full value and leave its tail rendered.
        usort($needles, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return $needles;
    }

    /**
     * `Basic base64("user:pass")` and the pair it encodes, plus each half.
     *
     * The guard mirrors the worker's own (`regional-probe.ts:1112`): a username
     * is required, and an EMPTY password is still a credential, since
     * `base64("user:")` is what gets sent for it. So the password is tested
     * with `is_string` and not for truthiness, and a credential the worker
     * would refuse to form contributes no needles, because a header that was
     * never sent cannot be echoed back.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function basicForms(array $config): array
    {
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;

        if (! is_string($username) || $username === '' || ! is_string($password)) {
            return [];
        }

        $pair = $username.':'.$password;

        return [
            base64_encode($pair),
            $pair,
            $username,
            $password,
        ];
    }

    /**
     * The bearer token, and the `Bearer ` scheme plus the token.
     *
     * The scheme-prefixed form is not redundant: it is the entry whose head
     * prefix catches `Bearer eyJhbGciOi`, the shape an echo takes when the page
     * truncates the whole header value rather than the token alone.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function bearerForms(array $config): array
    {
        $token = $config['token'] ?? null;

        if (! is_string($token) || $token === '') {
            return [];
        }

        return [
            'Bearer '.$token,
            $token,
        ];
    }

    /**
     * The api_key value alone.
     *
     * The header NAME is never a needle: it is not secret, the API already
     * emits it, and matching it would redact an ordinary diagnostic header on
     * every page that mentions it. Both fields are still required before the
     * key counts, mirroring the worker, which sends nothing without a name to
     * send it under.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function apiKeyForms(array $config): array
    {
        $key = $config['key'] ?? null;
        $header = $config['header'] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($header) || $header === '') {
            return [];
        }

        return [$key];
    }
}
