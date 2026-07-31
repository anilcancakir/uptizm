<?php

namespace App\Support\Monitoring;

/**
 * Matches a response `Content-Type` header against the pinned
 * `content-archive.allowed_content_types` rules.
 *
 * The semantics are implemented once here, in tested code, rather than
 * reimplemented at each call site: the Cloudflare probe worker mirrors the same
 * rules from the comment in config/content-archive.php, and the check pipeline
 * calls this helper as defence in depth against an older worker deployment that
 * might send an unfiltered content type.
 */
class ContentTypeAllowList
{
    /**
     * Determine whether a `Content-Type` header is allowed under the given
     * rules.
     *
     * Lowercases the raw header, cuts at the first `;` to drop parameters
     * such as `charset=utf-8`, trims the remainder, then accepts when it
     * equals an exact rule or begins with a prefix rule (a rule ending in
     * `/`). A null or empty header is always rejected.
     *
     * @param  string|null  $header  The raw `Content-Type` header value.
     * @param  list<string>  $rules  Lowercase rules; an entry ending in `/`
     *                               is a prefix match, any other entry is an
     *                               exact match.
     * @return bool True when the header is allowed by any rule.
     */
    public static function allows(?string $header, array $rules): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        // 1. Drop parameters (e.g. `; charset=utf-8`) and normalize case.
        $mediaType = strtolower(trim(explode(';', $header, 2)[0]));

        if ($mediaType === '') {
            return false;
        }

        // 2. Accept on the first exact or prefix match.
        foreach ($rules as $rule) {
            if (str_ends_with($rule, '/')) {
                if (str_starts_with($mediaType, $rule)) {
                    return true;
                }

                continue;
            }

            if ($mediaType === $rule) {
                return true;
            }
        }

        return false;
    }
}
