<?php

namespace App\Support\Ai;

/**
 * Decides which supported language a piece of model prose was written in.
 *
 * WHY IT COUNTS FUNCTION WORDS AND NOT LETTERS
 *
 * The obvious classifier is a character class: Turkish has `ç ğ ı ö ş ü` and
 * English does not. It is wrong here, and measurably so. The system templates in
 * this product interpolate an operator's own metric NAME into an English
 * sentence:
 *
 *     Redis İşlem Süresi (ms) returned to its normal range on fluttersdk.com
 *
 * That is an English sentence. A character class calls it Turkish, and calling
 * it Turkish is how an audit of the same data reported 91 missing translations
 * where 3 were missing. Function words do not have that failure: `returned to
 * its normal range on` is five English hits and no Turkish ones, and a Turkish
 * sentence carrying an English product name scores the mirror.
 *
 * WHY IT REFUSES TO ANSWER
 *
 * The caller acts on a WRONG-language verdict by spending another model call, so
 * a false positive costs money and a false negative costs nothing but the status
 * quo. Everything below is therefore biased toward `null`: too few words, too
 * few hits, or too thin a margin and it declines. `null` means "not sure", never
 * "neither", and the caller must treat it as "leave it alone".
 *
 * It knows exactly the two languages this product supports. A third one is a new
 * entry in {@see self::STOPWORDS} plus its own row in the test corpus, and until
 * then prose in a third language reads as `null`, which is the honest answer.
 */
class AnswerLanguage
{
    /**
     * The most frequent function words per locale, which is what makes this work
     * on a short sentence: content words vary with the subject, function words
     * do not.
     *
     * Turkish is agglutinative, so a stem here would match half the language;
     * these are whole words that stand alone, plus the handful of suffixed forms
     * (`için`, `üzerinde`) common enough to earn their place.
     *
     * @var array<string, list<string>>
     */
    private const STOPWORDS = [
        'en' => [
            'the', 'and', 'is', 'are', 'was', 'were', 'to', 'of', 'in', 'on', 'at',
            'for', 'with', 'this', 'that', 'it', 'its', 'has', 'have', 'been',
            'from', 'as', 'by', 'or', 'not', 'no', 'we', 'be', 'a', 'an', 'so',
            'but', 'than', 'then', 'there', 'when', 'while', 'which', 'because',
        ],
        'tr' => [
            've', 'bir', 'bu', 'için', 'ile', 'olarak', 'daha', 'çok', 'olan',
            'var', 'yok', 'gibi', 'kadar', 'sonra', 'önce', 'ancak', 'ama',
            'üzerinde', 'göre', 'her', 'de', 'da', 'ki', 'ise', 'hem', 'veya',
            'şu', 'o', 'en', 'çünkü', 'yani', 'değil', 'nedeniyle', 'tarafından',
        ],
    ];

    /**
     * Words a verdict needs before the ratio means anything.
     *
     * Under this the sample is a fragment, and a fragment can be a proper noun
     * and a number.
     */
    private const MINIMUM_WORDS = 6;

    /**
     * Function-word hits the winner needs on its own.
     *
     * A single `to` in an otherwise unreadable string is not evidence.
     */
    private const MINIMUM_HITS = 2;

    /**
     * How far ahead the winner must be, as a multiple of the runner-up.
     *
     * Turkish and English share a few tokens (`de`, `o`, `en`, `var` against `a`,
     * `an`, `no`), so a narrow lead is noise rather than a verdict.
     */
    private const MINIMUM_MARGIN = 2.0;

    /**
     * The locale [$text] appears to be written in, or null when unsure.
     *
     * @return string|null One of the keys of {@see self::STOPWORDS}, or null.
     */
    public static function detect(string $text): ?string
    {
        $words = self::words($text);

        if (count($words) < self::MINIMUM_WORDS) {
            return null;
        }

        $hits = [];

        foreach (self::STOPWORDS as $locale => $stopwords) {
            $hits[$locale] = count(array_intersect($words, $stopwords));
        }

        arsort($hits);

        $locales = array_keys($hits);
        $winner = $locales[0];
        $best = $hits[$winner];
        $runnerUp = count($locales) > 1 ? $hits[$locales[1]] : 0;

        if ($best < self::MINIMUM_HITS) {
            return null;
        }

        // A runner-up of zero is a clear win; otherwise the lead has to be wide.
        if ($runnerUp > 0 && $best < $runnerUp * self::MINIMUM_MARGIN) {
            return null;
        }

        return $winner;
    }

    /**
     * True only when [$text] is CONFIDENTLY in a language other than [$expected].
     *
     * The asymmetry is the whole point: an undetermined verdict answers false,
     * so the caller never spends a retry on prose this cannot read.
     */
    public static function differsFrom(string $text, string $expected): bool
    {
        $detected = self::detect($text);

        return $detected !== null && $detected !== self::normalize($expected);
    }

    /**
     * Lowercase words, with the region subtag rules of a locale left alone.
     *
     * `mb_strtolower` rather than `strtolower`, and the difference is not
     * cosmetic: the ASCII one leaves `İŞLEM` uppercase, and an uppercase token
     * matches no stopword, so a shouted sentence would score zero in both
     * languages and read as undetermined.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $lowered = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\p{L}]+/u', $lowered, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : array_values($parts);
    }

    /**
     * Drop a region subtag, matching {@see PromptLanguage::nameFor()}.
     */
    private static function normalize(string $locale): string
    {
        return strtolower(explode('-', $locale)[0]);
    }
}
