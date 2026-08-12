<?php

namespace App\Support\StatusPages;

use App\Enums\DomainMode;
use App\Models\StatusPage;
use Illuminate\Support\Str;
use Locale;

/**
 * Where each language of ONE status page lives: its canonical URL, the alternate
 * set a crawler reads, and the links the switcher renders.
 *
 * WHY ONE OBJECT RATHER THAN THREE CALLS
 *
 * "Which language" and "where does each language live" are one answer, and three
 * surfaces consume it: the switcher, the `<head>`'s canonical plus hreflang set,
 * and the sitemap's `xhtml:link` alternates. Google drops a whole alternate
 * cluster when the three disagree (reciprocity is enforced, and an alternate
 * that does not point back is ignored), so they are composed once here rather
 * than assembled separately per surface.
 *
 * ONE CANONICAL HOSTNAME PER PAGE, ONE URL PER LANGUAGE
 *
 * The same document answers on up to three hosts (`<app>/s/{slug}`,
 * `{slug}.<subdomain_host>`, and a `custom_domain`), so canonicalisation has to
 * be settled BEFORE hreflang means anything. The precedence is
 * {@see StatusPage::publicUrl()}'s, unchanged: custom domain, then subdomain,
 * then the path form. The two hostnames that lose point AT the winner through
 * the canonical they emit and never appear as alternates of their own, which is
 * the only way one document on three hosts can have one canonical per language.
 *
 * Within the winning hostname the default language keeps the unprefixed address
 * and every other takes a `/{code}` prefix, matching the routes registered in
 * `routes/status.php`, so there is exactly ONE address per language.
 *
 * Every URL is composed from CONFIGURATION and never from the incoming request.
 * `route()` and `url()` resolve against the request root, which would make the
 * canonical (and the OG url a crawler reads) differ per host and defeat the
 * point of emitting one at all.
 */
readonly class StatusPageChrome
{
    /**
     * @param  StatusPage  $page  The page being rendered; its `domain_mode`,
     *                            `custom_domain` and `slug` decide the hostname.
     * @param  string  $rendering  The language actually being rendered, from
     *                             {@see StatusPageLocale::render()}. It is what `canonicalUrl` names and
     *                             what the switcher marks `current`, so a page rendered under the route's
     *                             language is self-canonical rather than canonical to the owner's language.
     */
    public function __construct(
        public StatusPage $page,
        public string $rendering,
    ) {}

    /**
     * The chrome contract as view data.
     *
     * Spread into the status view's data by the controller, because the partials
     * that read it dereference their variables UNGUARDED: a render that forgets
     * one throws rather than emitting a head with a hole in it.
     *
     * @return array{
     *     canonicalUrl: string,
     *     defaultLocaleUrl: string,
     *     localeLinks: list<array{code: string, label: string, path: string, url: string, current: bool}>,
     *     alternates: list<array{hreflang: string, href: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'canonicalUrl' => $this->urlFor($this->rendering),
            'defaultLocaleUrl' => $this->urlFor($this->defaultLocale()),
            'localeLinks' => $this->localeLinks(),
            'alternates' => $this->alternates(),
        ];
    }

    /**
     * One alternate per supported language plus `x-default`.
     *
     * SELF-REFERENCING: the language being rendered declares itself too. Google
     * requires the set to include the page it is on, and a set that omits it is
     * treated as broken reciprocity and ignored wholesale.
     *
     * `x-default` is the fallback for a visitor whose language we do not speak,
     * and it points at THIS page in the default language rather than at any site
     * root.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    public function alternates(): array
    {
        $alternates = array_map(
            static fn (array $link): array => [
                'hreflang' => $link['code'],
                'href' => $link['url'],
            ],
            $this->localeLinks(),
        );

        $alternates[] = [
            'hreflang' => 'x-default',
            'href' => $this->urlFor($this->defaultLocale()),
        ];

        return $alternates;
    }

    /**
     * Every language the page is published in, with this page's address in each.
     *
     * `path` is the same-host relative form and `url` the absolute one: a
     * switcher links within the hostname it is rendered on, while a crawler and
     * the sitemap need the absolute address. Both name the same document,
     * because every language of a page lives on that page's ONE canonical host.
     *
     * @return list<array{code: string, label: string, path: string, url: string, current: bool}>
     */
    public function localeLinks(): array
    {
        return array_map(
            fn (string $code): array => [
                'code' => $code,
                'label' => $this->languageName($code),
                'path' => $this->pathFor($code),
                'url' => $this->urlFor($code),
                'current' => $code === $this->rendering,
            ],
            array_values((array) config('magic-starter.supported_locales', [])),
        );
    }

    /**
     * This page's absolute URL in one language, on its canonical hostname.
     */
    public function urlFor(string $locale): string
    {
        $host = $this->canonicalHost();

        return $host === null
            ? $this->appBase().$this->pathFor($locale)
            : $this->scheme().'://'.$host.$this->pathFor($locale);
    }

    /**
     * This page's path in one language, on its canonical hostname.
     *
     * The two hostname forms carry the page's identity differently: a subdomain
     * or a custom domain IS the page, so its path is the root, while on the app
     * host the slug is a path segment. The language prefix goes in front of
     * either, and the default language takes none.
     */
    public function pathFor(string $locale): string
    {
        $prefix = $locale === $this->defaultLocale() ? '' : '/'.$locale;

        if ($this->canonicalHost() === null) {
            return $prefix.'/s/'.$this->page->slug;
        }

        return $prefix === '' ? '/' : $prefix;
    }

    /**
     * The one hostname this page is canonical on, or null when that is the app
     * host and the page is addressed by path.
     *
     * The precedence and its guards mirror {@see StatusPage::publicUrl()} exactly,
     * including the fallback: a mode whose prerequisite is missing (a Custom page
     * with no domain, a Subdomain page in a deployment that configures no
     * subdomain host) falls back to the path form, which always resolves.
     */
    private function canonicalHost(): ?string
    {
        $customDomain = $this->page->custom_domain;

        if ($this->page->domain_mode === DomainMode::Custom && is_string($customDomain) && $customDomain !== '') {
            return $customDomain;
        }

        $subdomainHost = config('status_pages.subdomain_host');

        if ($this->page->domain_mode === DomainMode::Subdomain && is_string($subdomainHost) && $subdomainHost !== '') {
            return $this->page->slug.'.'.$subdomainHost;
        }

        return null;
    }

    /**
     * A language's name in its own language.
     *
     * Read from ICU rather than a typed label map, so a locale added to config
     * names itself instead of showing a two-letter code: somebody hunting for
     * Turkish scans for "Türkçe", not for "Turkish".
     */
    private function languageName(string $code): string
    {
        return Str::ucfirst(Locale::getDisplayLanguage($code, $code));
    }

    /**
     * The configured app root, without a trailing slash.
     */
    private function appBase(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * The scheme the deployment serves on, taken from the app root so a local
     * http deployment does not advertise https URLs it cannot answer.
     */
    private function scheme(): string
    {
        return parse_url($this->appBase(), PHP_URL_SCHEME) ?: 'https';
    }

    /**
     * The language served without a URL prefix. `app.default_locale`, never
     * `app.locale`, for the reason {@see StatusPageLocale} documents.
     */
    private function defaultLocale(): string
    {
        return (string) config('app.default_locale');
    }
}
