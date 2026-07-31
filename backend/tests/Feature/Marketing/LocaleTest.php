<?php

namespace Tests\Feature\Marketing;

use Tests\TestCase;

/**
 * The landing page's languages.
 *
 * The page speaks whatever the app speaks. The locale list is read from
 * `magic-starter.supported_locales`, the SAME array the API negotiates
 * `Accept-Language` against and the same pair the Flutter client registers, so a
 * language cannot exist on one surface and be missing from the other.
 *
 * Most of these iterate that config rather than naming `tr`, so adding a locale
 * turns them red until the new language has a URL, an alternate and a canonical
 * of its own.
 */
class LocaleTest extends TestCase
{
    public function test_the_apex_serves_the_default_locale(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<html lang="'.config('app.default_locale').'"', escape: false);
    }

    public function test_every_supported_language_has_a_page_of_its_own(): void
    {
        foreach ($this->supported() as $locale) {
            $this->get($this->pathFor($locale))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'"', escape: false);
        }
    }

    public function test_the_default_language_has_exactly_one_url(): void
    {
        // Otherwise `/` and `/en` are two English pages competing for the same
        // query, and hreflang has no single canonical to name for the language.
        $this->get('/'.config('app.default_locale'))
            ->assertStatus(301)
            ->assertRedirect('/');
    }

    public function test_a_language_we_do_not_speak_is_not_a_page(): void
    {
        foreach (['de', 'fr', 'es'] as $locale) {
            $this->get('/'.$locale)->assertNotFound();
        }
    }

    public function test_the_health_check_is_not_mistaken_for_a_language(): void
    {
        // The localized route is a two-letter path and `/up` is a two-letter path.
        // The constraint listing the real locales is what keeps the health endpoint
        // reachable, so this is a regression test for the whole deployment.
        $this->get('/up')->assertOk();
    }

    public function test_every_language_is_declared_as_an_alternate_of_every_other(): void
    {
        foreach ($this->supported() as $pageLocale) {
            $response = $this->get($this->pathFor($pageLocale));

            foreach ($this->supported() as $alternate) {
                $response->assertSee('hreflang="'.$alternate.'"', escape: false);
            }

            // The language-neutral fallback for a visitor whose language we do not
            // speak. Without it a crawler has no default to fall back on.
            $response->assertSee('hreflang="x-default"', escape: false);
        }
    }

    public function test_each_page_is_canonical_for_its_own_language(): void
    {
        foreach ($this->supported() as $locale) {
            $this->get($this->pathFor($locale))
                ->assertSee('rel="canonical" href="'.$this->urlFor($locale).'"', escape: false);
        }
    }

    public function test_the_switcher_reaches_every_language_without_javascript(): void
    {
        foreach ($this->supported() as $locale) {
            $response = $this->get($this->pathFor($locale));

            foreach ($this->supported() as $other) {
                $response->assertSee('href="'.$this->pathFor($other).'"', escape: false);
            }
        }
    }

    public function test_the_page_names_each_language_in_that_language(): void
    {
        // The endonym, matching the app's own switcher (`{'en': 'English', 'tr':
        // 'Türkçe'}`): somebody looking for Turkish is looking for "Türkçe".
        // Derived through intl rather than a typed label map, so a new locale
        // names itself.
        $this->get('/')
            ->assertSee('English')
            ->assertSee('Türkçe');
    }

    public function test_the_turkish_page_is_actually_translated(): void
    {
        // A locale that resolves but falls through to the English source strings is
        // the real failure mode here, and it looks fine in every assertion above.
        // So: a string that exists only in tr.json, and the absence of the English
        // one it replaces.
        $this->get('/tr')
            ->assertOk()
            ->assertSee('Ücretsiz başla')
            ->assertDontSee('Start free');
    }

    public function test_the_stage_speaks_the_page_language(): void
    {
        // The act labels are rendered by Alpine from a JS object, which is exactly
        // where an i18n gap hides: the server HTML looks translated while the
        // running animation relabels itself in English a second later.
        $this->get('/tr')
            ->assertSee('Yeni izleyici')
            ->assertDontSee('New monitor');
    }

    public function test_no_english_connective_leaks_into_a_translated_sentence(): void
    {
        /*
         * The platform claim assembles its list in PHP, and the conjunction between the
         * items was a literal `' and '`. The sentence AROUND it was translated, so
         * every "is this page in Turkish" check passed while the pill on screen read
         * "sırada iOS and Android". Only reading the rendered page caught it.
         */
        config(['app.client_platforms' => ['web' => 'live', 'ios' => 'soon', 'android' => 'soon']]);

        $this->get('/tr')
            ->assertOk()
            ->assertSee('iOS ve Android')
            ->assertDontSee('iOS and Android');
    }

    /**
     * The locales the whole product supports.
     *
     * @return list<string>
     */
    protected function supported(): array
    {
        return array_values((array) config('magic-starter.supported_locales'));
    }

    /**
     * The path a given language is served on. The default lives on the apex.
     *
     * Reads `app.default_locale`, not `app.locale`: the request under test calls
     * `App::setLocale()`, and that rewrites `app.locale` inside this very process,
     * so a helper reading it after the call sees the page's language rather than
     * the default and computes the wrong path for every language.
     */
    protected function pathFor(string $locale): string
    {
        return $locale === config('app.default_locale') ? '/' : '/'.$locale;
    }

    /**
     * The absolute URL for a language, as the canonical tag emits it.
     */
    protected function urlFor(string $locale): string
    {
        return $locale === config('app.default_locale')
            ? route('landing')
            : route('landing.localized', ['locale' => $locale]);
    }
}
