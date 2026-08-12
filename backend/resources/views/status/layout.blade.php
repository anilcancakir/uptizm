<!doctype html>
{{--
    Standalone shell for the public status page. This is NOT the authenticated
    app chrome: it carries its own <html>, its own Tailwind v4 build, and the
    server-side OG/meta tags a link-preview crawler reads.

    The page answers on up to three hosts (path, subdomain, custom domain), so
    the two URLs a crawler indexes on (canonical and og:url) come from
    `$canonicalUrl`, which the controller derives from configuration rather than
    from the request. Functional URLs (the subscribe POST) stay request-relative
    via `route()` so they post back to the host the visitor is actually on.

    The `lang` attribute is the one string here that is not copy, and it is the
    one that matters most to a screen reader (which picks its voice from it) and
    to a crawler. It reads the locale the controller applied from
    `status_pages.locale`, so it can never disagree with the language of the copy
    below it.
--}}
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{--
            The page's colours come from custom properties that flip on
            `prefers-color-scheme` (resources/css/app.css), so the DOCUMENT already
            follows the reader. This tells the browser to bring its own furniture
            along: the scrollbar, the subscribe form's field chrome, its autofill
            treatment and the focus ring are all UA-painted, and without this
            declaration they stay light on a dark page and the form ends up the one
            light rectangle on the surface.

            A meta rather than a CSS declaration because this shell is not the only
            document in the flow: the subscribe result pages carry the same tag and
            no layout, and app.css is shared with the marketing surface, whose scheme
            behaviour is not this pass's to change.
        --}}
        <meta name="color-scheme" content="light dark">

        <title>{{ $vm->page['name'] }}</title>

        {{--
            One canonical URL per language, and every language declared as an
            alternate of every other, in the same tag set
            `marketing/partials/seo-head.blade.php` emits. That partial is NOT
            included here: it also writes the title, the description and two
            theme-color metas, and this shell's are status-aware.

            `$canonicalUrl` is THIS language on THIS page's one canonical
            hostname, so a page reached on a losing host (the path form of a
            Subdomain-mode page, say) points at the winner and a page reached in
            Turkish is canonical to itself rather than to English. It carries no
            `hreflang` attribute: Google ignores a canonical that has one, which
            would leave the document with no canonical at all.

            The alternate set is SELF-REFERENCING (the language being rendered
            declares itself) and closes with `x-default`. Reciprocity is
            enforced: a set that omits its own page, or one that named a hostname
            other than the canonical one for that language, is treated as broken
            and the whole cluster is dropped.

            `$alternates` rather than a loop over `$localeLinks` plus a hand-added
            `x-default`, even though the emitted tags are identical: this is the
            same array `SitemapBuilder::statusPages()` puts in the XML, so the
            sitemap and the page cannot drift apart by construction rather than
            by both being edited. `StatusPageSeoTest` compares the two sets.

            All of it comes from `StatusPageChrome`, which composes from
            configuration and the row, never from the request: `url()` and
            `route()` resolve against the host the request arrived on, which is
            exactly what a canonical must not do here.
        --}}
        <link rel="canonical" href="{{ $canonicalUrl }}">

        @foreach ($alternates as $alternate)
            <link rel="alternate" hreflang="{{ $alternate['hreflang'] }}" href="{{ $alternate['href'] }}">
        @endforeach

        {{--
            The favicon carries the page's CURRENT status, so a pinned tab is a
            status light: green, amber, orange, red, or grey when nothing is
            published. Its colours come from the same map the banner dot uses
            (see $bannerDotClass in partials/status-banner.blade.php), so the tab
            and the page can never disagree.

            The 16px rendition is used deliberately. A `link rel=icon` cannot pick
            between sizes for an SVG, and the tab is the size that gets looked at,
            so the geometry optically corrected for 16px wins over the full mark
            that blurs there. See assets/brand/uptizm-mark-16.svg.

            It only updates on a page load. A status change is not pushed to an
            already-open tab, and pretending otherwise would need a poll this page
            deliberately does not run (it stays a cacheable static document).
        --}}
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon/'.\App\Support\StatusPages\StatusPresentation::faviconStem($vm->overallStatus).'-16.svg') }}">
        <meta name="theme-color" content="{{ \App\Support\StatusPages\StatusPresentation::themeColor($vm->overallStatus) }}">

        <meta property="og:title" content="{{ $vm->page['name'] }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        @if ($vm->page['description'])
            <meta property="og:description" content="{{ $vm->page['description'] }}">
            <meta name="description" content="{{ $vm->page['description'] }}">
        @endif

        {{--
            Pre-paint theme override. Auto light/dark already works through the
            `prefers-color-scheme` media query in app.css; this reads a stored
            EXPLICIT choice and sets `data-theme` on `<html>` before the
            stylesheet below resolves, so a returning visitor who chose light on
            a dark-preferring browser never sees a dark flash first. It has to
            run BEFORE `@vite`: app.css keys its override off `[data-theme]`, so
            setting the attribute after the stylesheet link would still paint
            once with the wrong theme.

            Storage shape and the try/catch copy
            resources/views/marketing/analytics.blade.php:73: `localStorage` can
            be unreachable rather than empty (Safari private mode, a
            block-all-storage setting), and a truncated record throws on parse,
            so the catch is a decision, not a swallow. No proven choice falls
            through to the media query rather than guessing.

            No cookie: `write()` below only ever touches `localStorage`, which
            never leaves the device and never appears in a `Set-Cookie` header.

            Exposed on `window` so the footer control
            (partials/footer.blade.php) reads and writes the same record
            through one function set instead of a second copy of the storage
            key and version literals that could drift from this one.
        --}}
        <script>
            window.__uptizmTheme = (function () {
                var STORAGE_KEY = 'uptizm-status-theme';
                var VERSION = 1;
                var CHOICES = ['light', 'dark'];

                function read() {
                    try {
                        var stored = window.localStorage.getItem(STORAGE_KEY);

                        if (stored === null) {
                            return null;
                        }

                        var record = JSON.parse(stored);

                        if (record && record.version === VERSION && CHOICES.indexOf(record.choice) !== -1) {
                            return record.choice;
                        }

                        return null;
                    } catch (error) {
                        return null;
                    }
                }

                function write(choice) {
                    try {
                        if (choice === null) {
                            window.localStorage.removeItem(STORAGE_KEY);
                        } else {
                            window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ version: VERSION, choice: choice }));
                        }
                    } catch (error) {
                        // Same unreachable-storage case as read(): the attribute
                        // apply() already set still holds for this page view, it
                        // just will not survive a reload.
                    }
                }

                function apply(choice) {
                    if (CHOICES.indexOf(choice) !== -1) {
                        document.documentElement.setAttribute('data-theme', choice);
                    } else {
                        document.documentElement.removeAttribute('data-theme');
                    }
                }

                apply(read());

                return { read: read, write: write, apply: apply };
            })();
        </script>

        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-surface text-fg antialiased">
        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            @yield('content')
        </div>

        {{-- Converts every `<time datetime>` on the page to the viewer's local
             time zone client-side; the server-rendered text stays as the
             no-JS fallback. Then flags the document as render-ready.

             The FORMAT comes from the page's language and the ZONE from the
             browser, which is the only split that reads correctly: where the
             reader is standing is a fact about them, and the language is the one
             they asked this page for. Passing no locale at all took both from the
             browser, which put "8/12/2026, 6:06:45 PM" directly under the
             Turkish heading "12 Ağustos 2026" on the same page. Caught on the
             live walk, in a headless Chrome running as en-US.

             `document.documentElement.lang` is written by this layout from
             `app()->getLocale()`, so it is always a tag Intl accepts; the
             `|| undefined` is for a browser reading a document that somehow
             carries no lang at all, where the browser default is the only
             remaining answer. --}}
        <script>
            var pageLocale = document.documentElement.lang || undefined;

            document.querySelectorAll('time[datetime]').forEach(function (el) {
                el.textContent = new Date(el.dateTime).toLocaleString(pageLocale);
            });

            // Render-ready marker. The headless preview renderer waits for
            // `[data-times-localized]` and treats its absence as a hard
            // failure, so this is a SUCCESS ASSERTION, not just a wait: only
            // this layout emits it, and a 404 or a 429 error page therefore
            // can never be stored as a completed customer-facing artefact.
            //
            // Two properties are load-bearing. It is set UNCONDITIONALLY
            // (outside the loop above, which a page with no incidents never
            // enters, and with a synchronous path for a browser that exposes
            // no `document.fonts`), because a marker that never fires would
            // hang the renderer for its whole timeout. And it is set only
            // after fonts settle, because a capture taken mid-swap stores a
            // visibly wrong image. A font that fails to load still marks
            // ready: an unstyled artefact beats no artefact plus a stalled job.
            var markRenderReady = function () {
                document.documentElement.dataset.timesLocalized = '1';
            };

            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(markRenderReady, markRenderReady);
            } else {
                markRenderReady();
            }
        </script>
    </body>
</html>
