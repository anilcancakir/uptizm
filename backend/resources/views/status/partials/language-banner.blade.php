{{--
    The language offer: this visitor's browser prefers a language the page is
    published in, and it is not the one being rendered.

    An OFFER, never a destination. Google's multi-regional guidance rules out
    redirecting a visitor from one language version to another, and Googlebot
    sends no `Accept-Language` at all, so a redirect would be both a ranking
    problem and invisible to the crawler that motivates the whole URL set. One
    click, and the URL the visitor lands on IS the choice: no cookie, no session.

    Rendered in the OFFERED language, with the locale passed EXPLICITLY to
    `__()` rather than taken from the ambient one. The page around this strip is
    in the language the visitor did not ask for; a line resolved from
    `app()->getLocale()` would offer Turkish in English to somebody who reads
    Turkish, which is the one reader this element exists for.
--}}
@if ($languageOffer !== null)
    @php
        /*
         * Dereferenced unguarded on purpose: `StatusPageLocale::offer()` and
         * `StatusPageChrome::localeLinks()` both enumerate
         * `StatusPageLocale::supported()`, ONE accessor, so an offer with no
         * link is a broken contract rather than a visitor-facing state, and it
         * should surface as a failure instead of a silently missing banner.
         *
         * The accessor is what makes that true. While the links came from
         * `magic-starter.supported_locales` read raw and the offer negotiated
         * against that array with `app.default_locale` PREPENDED, the two lists
         * differed by exactly the deployment default: with `APP_LOCALE=de` and
         * `['en', 'tr']` published, a German visitor was offered `de`, the
         * lookup below answered null and this public page 500'd.
         */
        $offered = collect($localeLinks)->firstWhere('code', $languageOffer);
    @endphp

    {{-- `lang` on the strip, because its text is in a different language than
         the document: it is what tells a screen reader to switch voice, and it
         is the attribute a crawler reads to see the offer is not English prose
         on an English page. --}}
    <div lang="{{ $languageOffer }}" class="mb-4 rounded-lg bg-info-soft px-4 py-3 text-sm text-info-soft-foreground">
        {{ __('status.language.offer', ['language' => $offered['label']], $languageOffer) }}

        <a
            href="{{ $offered['path'] }}"
            hreflang="{{ $languageOffer }}"
            class="font-semibold underline underline-offset-2"
        >{{ __('status.language.offer_action', ['language' => $offered['label']], $languageOffer) }}</a>
    </div>
@endif
