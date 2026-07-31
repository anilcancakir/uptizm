<?php

namespace App\Support\Marketing;

use App\Enums\MonitorRegion;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Locale;

/**
 * Every variable the shared marketing chrome dereferences, for ONE page.
 *
 * `marketing/layout.blade.php` carries the `<head>`, the skip link, the header and the
 * footer for every marketing page, and those partials dereference their data UNGUARDED:
 * `count($regions)`, `$platformClaim`, `$summary`, `$canonicalUrl`. A page that renders
 * the layout without one of them does not degrade, it throws. So the shell needs one
 * object that supplies the whole set, and every page builds one.
 *
 * WHY THIS SET, AND WHY IT IS NOT A LIST SOMEBODY TYPED
 *
 * The set below was DERIVED, by grepping the chrome and everything it includes for every
 * `$name` dereference:
 *
 *   grep -oE '\$[a-zA-Z_][a-zA-Z0-9_]*' resources/views/landing.blade.php \
 *       resources/views/marketing/{header,footer,language-menu,brand-mark}.blade.php
 *
 * which yields `$canonicalUrl $homePath $localeLinks $platformClaim $regions $sections
 * $signInUrl $signUpUrl $summary` once the `@foreach` locals (`$link`, `$section`) are
 * discounted. Three successive drafts of this work enumerated that list by hand instead
 * and each one missed a different name. The enforcement is therefore not this docblock:
 * it is `tests/Feature/Marketing/LayoutTest.php`, which renders a content page from
 * NOTHING but this object plus a document. Add a dereference to the chrome without
 * adding it here and that test throws.
 *
 * CANONICAL AND HREFLANG ARE PER PAGE
 *
 * The head used to name the landing route directly, which was correct while the landing
 * page was the only page. A legal page rendering through the same head would have
 * declared itself a copy of the home page and told every crawler the two documents were
 * the same. So the URLs are composed from `$path` instead, and `url()` replaces the
 * `route()` calls the landing controller used: both take their root from the same
 * `UrlGenerator::formatRoot()`, so the canonical host is unchanged (verified: `url('/')`
 * and `route('landing')` both return the configured root, `url('/tr')` and
 * `route('landing.localized')` both return root + `/tr`), and composition works for a
 * page whose route is registered under a name this class cannot know.
 */
readonly class ChromeData
{
    /**
     * @param  string  $path  This page's path with NO locale prefix and no leading slash
     *                        required: '' for the site root, 'privacy' for the Privacy page. The locale prefix
     *                        and the absolute form are composed from it, per language.
     * @param  string  $summary  This page's own one-sentence meta description. Per page,
     *                           never the landing page's: it is what a crawler and a link preview show, so a
     *                           legal page reusing the home page's sentence describes the wrong document.
     * @param  list<array{id: string, label: string}>  $sections  The in-page anchors on THIS
     *                                                            page, which the header nav and the footer's product column render from. Empty
     *                                                            by default because most pages have none, and handing a page the landing page's
     *                                                            list would put eight nav links on it pointing at ids it never emits.
     *                                                            `ChromeTest::test_no_in_page_anchor_points_at_a_missing_section` fails the build
     *                                                            on exactly that.
     */
    public function __construct(
        public string $path,
        public string $summary,
        public array $sections = [],
    ) {}

    /**
     * The chrome contract as view data.
     *
     * Spread into a page's own view data (`...$chrome->toArray()`), so a page adds its
     * body's variables and never restates the shell's.
     *
     * @return array{
     *     sections: list<array{id: string, label: string}>,
     *     summary: string,
     *     canonicalUrl: string,
     *     defaultLocaleUrl: string,
     *     localeLinks: list<array{code: string, label: string, path: string, url: string, current: bool}>,
     *     homePath: string,
     *     signInUrl: string,
     *     signUpUrl: string,
     *     regions: list<array{value: string, label: string}>,
     *     platformClaim: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'sections' => $this->sections,
            'summary' => $this->summary,
            'canonicalUrl' => $this->urlFor(app()->getLocale()),
            'defaultLocaleUrl' => $this->urlFor($this->defaultLocale()),
            'localeLinks' => $this->localeLinks(),
            'homePath' => $this->localizedPath(app()->getLocale(), ''),
            'signInUrl' => $this->clientUrl('/login'),
            'signUpUrl' => $this->clientUrl('/register'),
            'regions' => $this->regions(),
            'platformClaim' => $this->platformClaim(),
        ];
    }

    /**
     * Every language the product speaks, with THIS page's address in each.
     *
     * The list is `magic-starter.supported_locales`, the same array the API negotiates
     * Accept-Language against and the same pair the Flutter client registers, so the
     * three surfaces cannot drift apart.
     *
     * The `path` is the page's own, not the home page's: a switcher that drops a reader
     * back on the front page halfway down a privacy notice has lost their place.
     *
     * @return list<array{code: string, label: string, path: string, url: string, current: bool}>
     */
    private function localeLinks(): array
    {
        $current = app()->getLocale();

        return array_map(
            fn (string $code): array => [
                'code' => $code,
                'label' => $this->languageName($code),
                'path' => $this->localizedPath($code, $this->path),
                'url' => $this->urlFor($code),
                'current' => $code === $current,
            ],
            array_values((array) config('magic-starter.supported_locales', [])),
        );
    }

    /**
     * A language's name in its own language.
     *
     * Read from ICU rather than a typed label map, so a locale added to config names
     * itself instead of showing a two-letter code. This matches the app's own switcher
     * (`English`, `Türkçe`): somebody hunting for Turkish scans for "Türkçe", not for
     * "Turkish".
     */
    private function languageName(string $code): string
    {
        return Str::ucfirst(Locale::getDisplayLanguage($code, $code));
    }

    /**
     * The absolute URL for this page in one language, for canonical and hreflang.
     */
    private function urlFor(string $locale): string
    {
        return url($this->localizedPath($locale, $this->path));
    }

    /**
     * Compose a path from a language and a page path. The default language lives on the
     * apex, so it takes no prefix and there is exactly ONE url per language per page.
     */
    private function localizedPath(string $locale, string $path): string
    {
        $segments = [];

        if ($locale !== $this->defaultLocale()) {
            $segments[] = $locale;
        }

        $page = trim($path, '/');

        if ($page !== '') {
            $segments[] = $page;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * The language served without a path prefix.
     *
     * `app.default_locale` and NEVER `app.locale`: `App::setLocale()` rewrites the latter
     * for the rest of the request, and every marketing page runs under a locale set from
     * its path, so reading `app.locale` here would make "the default language" follow the
     * visitor. That exact bug inverted every language-switcher link on `/tr`.
     */
    private function defaultLocale(): string
    {
        return (string) config('app.default_locale');
    }

    /**
     * The probe regions a monitor can actually be pinned to, which the footer counts.
     *
     * Sourced from the enum the write requests validate against, NOT from
     * `config/relay.php`'s `regions` key, which the dispatch path never reads and which
     * understates the real list.
     *
     * @return list<array{value: string, label: string}>
     */
    private function regions(): array
    {
        return array_map(
            fn (MonitorRegion $region): array => [
                'value' => $region->value,
                'label' => $region->label(),
            ],
            MonitorRegion::cases(),
        );
    }

    /**
     * What we may honestly say about where the client runs.
     *
     * A plain list of the platforms the client is built for, read from
     * `app.client_platforms` so adding or dropping one moves the footer line with it. The
     * names are proper nouns and are not translated.
     *
     * What the page must never claim is a listing that does not exist: no App Store or
     * Google Play badge, no download link. The mobile builds come from the same Flutter
     * source but are in neither store, so nobody can install them yet. `HeroTest` pins
     * that absence.
     */
    private function platformClaim(): string
    {
        return Arr::join(
            array_map(
                fn (string $key): string => $key === 'ios' ? 'iOS' : ucfirst($key),
                array_keys((array) config('app.client_platforms', [])),
            ),
            ', ',
        );
    }

    /**
     * Absolute URL into the Flutter client.
     *
     * The client mounts its auth screens under a prefix, so these are NOT `/login` and
     * `/register` on the API host; getting it wrong sends every visitor who clicks the
     * primary call to action to a route the client does not serve.
     */
    private function clientUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .rtrim((string) config('app.frontend_auth_prefix'), '/')
            .$path;
    }
}
