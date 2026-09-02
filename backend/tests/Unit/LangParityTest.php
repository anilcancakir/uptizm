<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the `en` and `tr` language catalogues against each other.
 *
 * The Flutter half has had `test/config/lang_parity_test.dart` for a while;
 * this is the backend's equivalent, and it arrived with 340 newly translated
 * lines that had no guard at all. Three separate ways a translation goes wrong
 * silently, each with its own case below, because none of them fails a build,
 * a linter, or any other test:
 *
 * 1. A key present in one locale and absent in the other. Laravel falls back
 *    to the fallback locale, so a Turkish operator reads one English sentence
 *    among Turkish ones and nothing reports it.
 * 2. A `:placeholder` renamed or dropped in translation. The token then renders
 *    literally, so a person sees ":attribute" or a message missing the number
 *    it was supposed to carry.
 * 3. Two words glued together. This one is here because it actually happened:
 *    a global string removal across `lang/tr/validation.php` swallowed the
 *    space on both sides of the word it removed, and the check I ran afterwards
 *    only looked for glue next to a `:placeholder`, so six broken sentences on
 *    the most frequently triggered rules in the app (`required_if`,
 *    `required_with`) shipped as "olduğundagereklidir".
 */
class LangParityTest extends TestCase
{
    /**
     * The four files `php artisan lang:publish` produces, plus this app's own.
     *
     * @var list<string>
     */
    private const array FILES = [
        'validation',
        'auth',
        'passwords',
        'pagination',
        'guards',
        'notifications',
        'incidents',
        'monitors',
        'plans',
        'assistant',
        'status',
    ];

    /**
     * Turkish words that only ever stand as their own token.
     *
     * A lowercase letter directly in front of one of these is a word glued to
     * its neighbour. Kept as an explicit list rather than a morphological rule:
     * the point is to catch a mechanical editing accident, not to lint Turkish.
     *
     * @var list<string>
     */
    private const array STANDALONE_TR_WORDS = [
        'gereklidir',
        'olmalıdır',
        'zorunludur',
        'olabilir',
        'içermelidir',
        'içerebilir',
        'edilmelidir',
        'oluşmalıdır',
        'eşleşmelidir',
        'doldurulmalıdır',
        'kullanmalıdır',
        'önce',
        'sonra',
    ];

    public function test_every_key_exists_in_both_locales(): void
    {
        foreach (self::FILES as $file) {
            $en = $this->flatten($this->load('en', $file));
            $tr = $this->flatten($this->load('tr', $file));

            $missing = array_diff(array_keys($en), array_keys($tr));
            $extra = array_diff(array_keys($tr), array_keys($en));

            $this->assertSame(
                [],
                array_values($missing),
                "lang/tr/$file.php is missing keys present in lang/en/$file.php",
            );
            $this->assertSame(
                [],
                array_values($extra),
                "lang/tr/$file.php has keys absent from lang/en/$file.php",
            );
        }
    }

    public function test_every_translation_keeps_its_placeholders(): void
    {
        foreach (self::FILES as $file) {
            $en = $this->flatten($this->load('en', $file));
            $tr = $this->flatten($this->load('tr', $file));

            foreach ($en as $key => $value) {
                if (! is_string($value) || ! isset($tr[$key]) || ! is_string($tr[$key])) {
                    continue;
                }

                $this->assertSame(
                    $this->placeholders($value),
                    $this->placeholders($tr[$key]),
                    "lang/tr/$file.php \"$key\" does not carry the same "
                    .'placeholders as its English line',
                );
            }
        }
    }

    public function test_no_turkish_line_has_two_words_glued_together(): void
    {
        $pattern = '/[a-zçğıöşü](?:'
            .implode('|', array_map('preg_quote', self::STANDALONE_TR_WORDS))
            .')/u';

        foreach (self::FILES as $file) {
            foreach ($this->flatten($this->load('tr', $file)) as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $value,
                    "lang/tr/$file.php \"$key\" looks like it lost a space: "
                    ."\"$value\"",
                );
            }
        }
    }

    /**
     * Load one catalogue file, or an empty array when a locale does not ship it.
     *
     * A file only one locale has is caught by the key-parity case above through
     * whichever side does have it, so a missing file is not silently skipped.
     *
     * @return array<string, mixed>
     */
    private function load(string $locale, string $file): array
    {
        $path = base_path("lang/$locale/$file.php");

        if (! is_file($path)) {
            return [];
        }

        /** @var array<string, mixed> $lines */
        $lines = require $path;

        return $lines;
    }

    /**
     * Flatten a nested catalogue into dotted keys.
     *
     * @param  array<string, mixed>  $lines
     * @return array<string, mixed>
     */
    private function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            $path = $prefix === '' ? (string) $key : "$prefix.$key";

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * The sorted, lowercased set of `:placeholder` tokens in a message.
     *
     * @return list<string>
     */
    private function placeholders(string $message): array
    {
        preg_match_all('/:[A-Za-z_]+/', $message, $matches);

        $tokens = array_values(array_unique(array_map('strtolower', $matches[0])));
        sort($tokens);

        return $tokens;
    }
}
