<?php

namespace App\Support\Ai;

use FlutterSdk\MagicStarter\Support\RequestLocaleDetector;

/**
 * The language a model should write operator-facing free text in.
 *
 * It exists because a locale CODE is a weak instruction and a language NAME is a
 * strong one: "write the label in tr" is a token a model may or may not resolve,
 * while "write the label in Turkish" is unambiguous. The mapping is closed to the
 * locales the product actually ships (`magic-starter.supported_locales`), so an
 * unknown or absent locale falls back to English rather than asking a provider to
 * guess at a tag.
 *
 * The gap it closes is visible in production: metric discovery returned labels
 * like `database_latency_ms` to an operator whose entire interface is Turkish.
 * Those are not names, they are the keys the backend had already sent, and the
 * model had been given no reason to do better.
 */
class PromptLanguage
{
    /**
     * Locale code to the English name of that language.
     *
     * English names on purpose: the instruction around them is English, and a
     * model follows "in Turkish" more reliably than "in Türkçe" when the rest of
     * the sentence is not Turkish.
     */
    protected const array NAMES = [
        'en' => 'English',
        'tr' => 'Turkish',
    ];

    /**
     * The fallback, and the only value an unrecognised locale can produce.
     */
    public const string FALLBACK = 'English';

    /**
     * The language name for `$locale`, or the fallback.
     *
     * A region subtag is dropped (`tr-TR` is Turkish), matching how
     * {@see RequestLocaleDetector::detectLocale()}
     * negotiates the header the stored locale originally came from.
     */
    public static function nameFor(?string $locale): string
    {
        if ($locale === null || $locale === '') {
            return self::FALLBACK;
        }

        $language = strtolower(explode('-', $locale)[0]);

        return self::NAMES[$language] ?? self::FALLBACK;
    }
}
