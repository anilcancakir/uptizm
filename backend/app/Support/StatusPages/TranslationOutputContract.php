<?php

namespace App\Support\StatusPages;

use App\Jobs\TranslateStatusPageText;
use App\Services\Ai\MetricDiscoveryPayload;

/**
 * The mechanical gate every machine translation passes before it is published.
 *
 * WHY THIS EXISTS AT ALL. The fence this codebase already uses for untrusted
 * text ({@see MetricDiscoveryPayload}) gets its real safety from a CLOSED ANSWER
 * SPACE: whatever the model writes back is resolved against a catalog the
 * backend minted ({@see MetricDiscoveryPayload::isKnownRef()}), so an invented
 * answer resolves to nothing and is dropped. Translation has no such catalog.
 * Its answer space is "any sentence", the input is operator-authored free text
 * that a third party can influence, and the output is published verbatim on a
 * public, indexed, customer-branded page with no human review. The fence still
 * ships (it is what makes an instruction inside the text read as text), but it
 * cannot be the only control, so this is the one that runs on the way OUT.
 *
 * WHAT IT CHECKS, AND WHAT IT DELIBERATELY DOES NOT. Every rule here is
 * MECHANICAL: it compares the output against the SOURCE, never against a notion
 * of a good translation. Nothing in this class knows Turkish. A translation that
 * is merely bad (wrong register, a mistranslated technical term) passes, and
 * that is an accepted risk of the surface rather than an oversight: during an
 * outage nobody reviews translations, so a review gate means the reader waits
 * for the incident to end.
 *
 * The rules, in the order they run:
 *
 *  1. ENCODING. Invalid UTF-8, which is what a response truncated mid-codepoint
 *     looks like, and the only shape a lone surrogate can take in UTF-8.
 *     It runs FIRST rather than second (the plan's order) for a mechanical
 *     reason: every rule below is a `/u` pattern, and a `/u` pattern does not
 *     merely mismatch on invalid UTF-8, it fails outright and returns null. The
 *     strip cannot literally be first because it cannot run at all.
 *  2. UNSAFE CHARACTERS. C0/C1 controls, bidi overrides and isolates, and
 *     zero-width characters are stripped, and the output is REJECTED when the
 *     strip changed it. Rejecting rather than silently publishing the cleaned
 *     string is the point: a trailing U+202E makes a rendered line read
 *     backwards, and that is a fact worth recording rather than swallowing.
 *     Tab, newline and carriage return survive, because a postmortem body's
 *     newlines are layout on the rendered page and not control characters.
 *  3. FOREIGN TOKENS. Every URL host, bare hostname, email address and long
 *     digit run in the output must already appear in the source. This is the
 *     control that catches the concrete payload this path invites: an injected
 *     support URL or phone number, arriving inside text the model was asked to
 *     translate and reaching a page a customer's users trust. Membership is
 *     EXACT, never a suffix match, so `acme.com.evil.example` does not ride on a
 *     source that mentioned `acme.com`.
 *  4. LENGTH RATIO. Under 0.4x or over 3.0x the source, which is what an
 *     ignored instruction looks like, skipped entirely under 40 source
 *     characters where the ratio is noise (a 9-character "Resolved." legitimately
 *     becomes an 8-character "Çözüldü.").
 *  5. EMPTY. An output that is nothing but whitespace is not a translation. The
 *     ratio floor above means a short source cannot catch this, so it is its own
 *     rule rather than a side effect of one.
 *
 * THE FALSE POSITIVE IT ACCEPTS. Rule 3's bare-hostname pattern matches
 * `label.tld` anywhere, so a translation that drops the space after a full stop
 * (`...tamamlandı.Sunucu`) reads as a hostname and is rejected. That is
 * deliberate and the trade is one-directional: a false positive costs one field
 * rendering in its original language with a visible "translation unavailable"
 * note ({@see TranslateStatusPageText} records the reason and does not retry),
 * while a false negative publishes an attacker's phone number under the
 * customer's brand. Narrowing the pattern to a curated TLD list would trade that
 * asymmetry the wrong way and freeze a roster nobody will revisit.
 */
class TranslationOutputContract
{
    /**
     * The output carries a URL host, hostname, email address or long digit run
     * the source did not.
     */
    public const string REASON_FOREIGN_TOKEN = 'foreign_token';

    /**
     * The output is under 0.4x or over 3.0x the source length.
     */
    public const string REASON_LENGTH_RATIO = 'length_ratio';

    /**
     * The output carried a control, bidi or zero-width character.
     */
    public const string REASON_UNSAFE_CHARACTERS = 'unsafe_characters';

    /**
     * The output is not valid UTF-8: a response cut mid-codepoint, or a lone
     * surrogate, which is the only shape either can take here.
     */
    public const string REASON_TRUNCATED_TAIL = 'truncated_tail';

    /**
     * The output held nothing but whitespace.
     */
    public const string REASON_EMPTY = 'empty';

    /**
     * Source length below which the length ratio is not evaluated.
     */
    public const int RATIO_FLOOR_CHARACTERS = 40;

    /**
     * Smallest accepted output-to-source character ratio.
     */
    public const float MINIMUM_LENGTH_RATIO = 0.4;

    /**
     * Largest accepted output-to-source character ratio.
     */
    public const float MAXIMUM_LENGTH_RATIO = 3.0;

    /**
     * Digits a run must reach before it counts as a phone-number-shaped token,
     * once the separators between its own digits are removed.
     */
    public const int DIGIT_RUN_LENGTH = 7;

    /**
     * Characters removed before the comparison, whose removal is itself a
     * rejection: C0 controls except tab/newline/carriage return, DEL and the C1
     * block, the zero-width family, and the bidi overrides and isolates.
     */
    private const string UNSAFE_CHARACTER_PATTERN =
        '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}'
        .'\x{200B}-\x{200D}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    /**
     * Verify one translation against its source.
     *
     * @param  string  $source  The operator-authored text that was translated.
     * @param  string  $output  The model's raw answer, before any normalisation.
     * @return array{accepted: bool, value: string|null, reason: string|null} The
     *                                                                        verdict, the normalised value to publish (null on rejection, so a
     *                                                                        caller cannot store the suspect text by accident), and the machine
     *                                                                        reason (null on acceptance).
     */
    public static function verify(string $source, string $output): array
    {
        // 1. Encoding first: every rule below is a `/u` pattern, which fails
        //    outright rather than mismatching on invalid UTF-8.
        if (! mb_check_encoding($output, 'UTF-8')) {
            return self::reject(self::REASON_TRUNCATED_TAIL);
        }

        // 2. Strip, then reject on the strip having changed anything, so the
        //    reason is recorded rather than silently swallowed.
        $stripped = preg_replace(self::UNSAFE_CHARACTER_PATTERN, '', $output);

        if ($stripped === null || $stripped !== $output) {
            return self::reject(self::REASON_UNSAFE_CHARACTERS);
        }

        $value = trim($stripped);

        if ($value === '') {
            return self::reject(self::REASON_EMPTY);
        }

        // 3. The security rule: nothing addressable may appear that the source
        //    did not already carry.
        if (array_diff(self::tokens($value), self::tokens($source)) !== []) {
            return self::reject(self::REASON_FOREIGN_TOKEN);
        }

        // 4. The ratio, on characters rather than bytes: Turkish spends more
        //    bytes per character than English and a byte ratio would reject
        //    honest translations for existing.
        $sourceLength = mb_strlen(trim($source));

        if ($sourceLength >= self::RATIO_FLOOR_CHARACTERS) {
            $ratio = mb_strlen($value) / $sourceLength;

            if ($ratio < self::MINIMUM_LENGTH_RATIO || $ratio > self::MAXIMUM_LENGTH_RATIO) {
                return self::reject(self::REASON_LENGTH_RATIO);
            }
        }

        return [
            'accepted' => true,
            'value' => $value,
            'reason' => null,
        ];
    }

    /**
     * Every addressable token in a text, normalised so an honest reformat
     * produces the same set: hosts and email addresses lowercased, digit runs
     * with their internal separators removed.
     *
     * Each token carries its class as a prefix so the set stays readable while
     * debugging and two classes can never collide on one string.
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        return array_values(array_unique([
            ...self::urlHosts($text),
            ...self::emails($text),
            ...self::bareHosts($text),
            ...self::digitRuns($text),
        ]));
    }

    /**
     * The host of every scheme-carrying URL.
     *
     * The host alone, not the whole URL: a source that already points at the
     * customer's own status host is not made more dangerous by a path, while a
     * host the source never named is exactly the injected destination this rule
     * exists to catch.
     *
     * @return list<string>
     */
    private static function urlHosts(string $text): array
    {
        preg_match_all('/\b(?:https?|ftp):\/\/[^\s<>"\']+/iu', $text, $matches);

        $hosts = [];

        foreach ($matches[0] as $url) {
            // Trailing sentence punctuation belongs to the sentence, not to the
            // URL, and a source that ends a sentence on a link would otherwise
            // never match the same link mid-sentence in the translation.
            $host = parse_url(rtrim($url, '.,;:!?)]}»"\''), PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = 'host:'.mb_strtolower($host);
            }
        }

        return $hosts;
    }

    /**
     * Every email address, plus its domain as a host in its own right, so an
     * injected `support@evil.example` fails on both counts.
     *
     * @return list<string>
     */
    private static function emails(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}._%+\-]+@([\p{L}\p{N}.\-]+\.[a-z]{2,24})/iu', $text, $matches, PREG_SET_ORDER);

        $tokens = [];

        foreach ($matches as $match) {
            $tokens[] = 'mail:'.mb_strtolower($match[0]);
            $tokens[] = 'host:'.mb_strtolower($match[1]);
        }

        return $tokens;
    }

    /**
     * Every hostname written without a scheme.
     *
     * The lookbehind keeps this off text that another rule already owns: a host
     * inside a URL is preceded by `/`, an email domain by `@`, and neither
     * should be counted twice under a different prefix. The lookahead keeps a
     * partial label from matching inside a longer word.
     *
     * @return list<string>
     */
    private static function bareHosts(string $text): array
    {
        preg_match_all(
            '/(?<![\p{L}\p{N}@.\-\/])((?:[\p{L}\p{N}][\p{L}\p{N}\-]*\.)+[a-z]{2,24})(?![\p{L}\p{N}\-])/iu',
            $text,
            $matches,
        );

        return array_map(
            static fn (string $host): string => 'host:'.mb_strtolower($host),
            $matches[1],
        );
    }

    /**
     * Every digit run long enough to be a phone number, once the separators
     * BETWEEN ITS OWN DIGITS are removed.
     *
     * Between the digits rather than everywhere, which is the one place this
     * departs from a literal reading of "after removing spaces, hyphens and
     * parentheses". Removing them globally fuses numbers that were never one
     * number: "12 failures across 34567 checks" collapses to a 7-digit token
     * that exists in no phone book, and the honest Turkish translation, which
     * puts the two numbers in the other order, then reads as a changed phone
     * number and is rejected. Restricting the removal to separators flanked by
     * digits keeps "(555) 010-0123" a single token and leaves unrelated numbers
     * apart.
     *
     * @return list<string>
     */
    private static function digitRuns(string $text): array
    {
        $joined = preg_replace('/(?<=[0-9])[\s\-()]+(?=[0-9])/u', '', $text) ?? $text;

        preg_match_all('/[0-9]{'.self::DIGIT_RUN_LENGTH.',}/u', $joined, $matches);

        return array_map(
            static fn (string $digits): string => 'digits:'.$digits,
            $matches[0],
        );
    }

    /**
     * A rejection verdict. The value is always null: the suspect text is never
     * handed back, so no caller can store or log it by accident.
     *
     * @return array{accepted: bool, value: string|null, reason: string|null}
     */
    private static function reject(string $reason): array
    {
        return [
            'accepted' => false,
            'value' => null,
            'reason' => $reason,
        ];
    }
}
