<?php

namespace App\Support\StatusPages;

use App\Models\StatusPage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Which language a public status page renders in, and which language to OFFER a
 * visitor whose browser asks for a different one.
 *
 * Two decisions, both made per request and neither of them stored: the URL is
 * the only place a language choice lives on this surface (no cookie, no
 * session), and the browser's own preference is an offer rather than a
 * destination, because a public page that redirects on `Accept-Language` shows a
 * crawler one of its languages and traps a visitor who wants the other.
 *
 * It is a class rather than two lines in the controller for one reason: the
 * negotiation below has a documented trap that answers with the WRONG language,
 * and a trap is only closed once somebody can assert it in isolation instead of
 * through a rendered page.
 *
 * It also answers the question the whole URL space of a page hangs off, which is
 * a fact about the PAGE rather than about this request: which language the
 * unprefixed URL serves ({@see self::canonical()}). That answer lives here so the
 * routes, the controller, the chrome and the sitemap cannot each derive their own.
 *
 * The supported list is `magic-starter.supported_locales`, the same array the
 * API negotiates against, the marketing routes build their prefixes from and the
 * status routes register their locale segment from. Its entries are plain
 * language codes (`en`, `tr`), which is also what {@see self::offer()} compares
 * the negotiator's answer against: Symfony canonicalises a locale on the way out
 * (`pt-BR` becomes `pt_BR`), and that transformation is the identity for a bare
 * language code.
 */
class StatusPageLocale
{
    /**
     * The language to render this page in.
     *
     * The URL wins, then the page's own column, then the deployment default. The
     * route segment is checked against the supported list even though the route
     * itself already constrains it with `whereIn`: this is also called with
     * whatever a future caller hands it, and an unsupported code would render a
     * page of dotted catalogue keys under a customer's brand.
     */
    public static function render(?string $routeLocale, StatusPage $page): string
    {
        if ($routeLocale !== null && in_array($routeLocale, self::supported(), true)) {
            return $routeLocale;
        }

        return self::canonical($page);
    }

    /**
     * The language this page's UNPREFIXED URL serves, which is the one language of
     * this page that gets no prefixed form at all.
     *
     * The owner's column decides it, so it is a per-PAGE fact and not a
     * deployment-wide one: on a page published in Turkish, `/s/acme` IS Turkish
     * and English lives at `/en/s/acme`. Four layers depend on the same answer
     * (the routes accept every language because they cannot know the page, the
     * controller 404s the prefix that would duplicate this one, the chrome leaves
     * this one unprefixed, the sitemap publishes its URL as the `loc`), and they
     * read it HERE rather than each deriving it, because when three of them read
     * `app.default_locale` instead the page published an `hreflang="en"` naming a
     * URL that serves Turkish and a canonical no route answered.
     */
    public static function canonical(StatusPage $page): string
    {
        return $page->locale ?? self::defaultLocale();
    }

    /**
     * The supported language this visitor's browser prefers, when it is NOT the
     * one being rendered, and null otherwise.
     *
     * Null is the common answer and every null path is deliberate:
     *
     *   - The visitor already reads the language on screen, so there is nothing
     *     to offer.
     *   - The visitor speaks none of the languages we publish in. This is the
     *     trap the class exists for. `getPreferredLanguage()` returns
     *     `$locales[0]` on a TOTAL mismatch rather than null, so a German
     *     visitor negotiating against `['en', 'tr']` is answered `en`, and on a
     *     Turkish page that answer would raise a banner offering a language the
     *     visitor never asked for. The default locale goes FIRST in the list so
     *     that fallback is the deployment default rather than an arbitrary
     *     language, and the answer is then checked back against what the visitor
     *     actually named.
     *   - No `Accept-Language` at all, which is what Googlebot sends. Same
     *     check, same null: the header supplied nothing to match.
     *
     * The supported list is ALWAYS passed. Called with no argument the helper
     * returns the browser's raw top tag, unfiltered by anything we publish.
     */
    public static function offer(Request $request, string $rendering): ?string
    {
        $preferred = $request->getPreferredLanguage(self::supported());

        if ($preferred === null || $preferred === $rendering) {
            return null;
        }

        return self::visitorNamed($request, $preferred) ? $preferred : null;
    }

    /**
     * Whether the visitor's own `Accept-Language` really named this language.
     *
     * The region subtag is dropped on the way in (`tr-TR` prefers `tr`) because
     * that is the same widening the negotiator matches with; what this does not
     * do is widen the other way, so a visitor asking for `pt-PT` has not named a
     * supported `pt-BR`.
     */
    private static function visitorNamed(Request $request, string $language): bool
    {
        foreach ($request->getLanguages() as $tag) {
            if (strcasecmp($tag, $language) === 0 || strcasecmp(Str::before($tag, '_'), $language) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every language this deployment publishes in, the default one FIRST.
     *
     * The order is load bearing rather than cosmetic: it is the value
     * `getPreferredLanguage()` falls back to when it matches nothing, and
     * {@see self::offer()} reads that fallback as "no offer".
     *
     * Public because THREE surfaces have to enumerate the same list and cannot be
     * allowed to compose their own: the status routes register a segment per
     * entry, {@see StatusPageChrome::localeLinks()} emits a switcher link and an
     * hreflang alternate per entry, and the negotiation above offers one. Reading
     * `magic-starter.supported_locales` raw is what made them differ, because this
     * list PREPENDS `app.default_locale`: with a default absent from that array a
     * visitor was offered a language the switcher had no link for, and the banner
     * dereferences that link unguarded, so a PUBLIC page answered 500.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return array_values(array_unique([
            self::defaultLocale(),
            ...array_values((array) config('magic-starter.supported_locales', [])),
        ]));
    }

    /**
     * The language a page with no `locale` of its own publishes in.
     *
     * `app.default_locale` and never `app.locale`: the latter is rewritten by
     * `App::setLocale()` for the rest of the request, so reading it here would
     * make "the default language" follow whichever page is being rendered. That
     * exact bug once inverted every link in the marketing switcher.
     */
    private static function defaultLocale(): string
    {
        return (string) config('app.default_locale');
    }
}
