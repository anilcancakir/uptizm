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
--}}
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $vm->page['name'] }}</title>

        <link rel="canonical" href="{{ $canonicalUrl }}">

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

        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            @yield('content')
        </div>

        {{-- Converts every `<time datetime>` on the page to the viewer's local
             time zone client-side; the server-rendered text stays as the
             no-JS fallback. Then flags the document as render-ready. --}}
        <script>
            document.querySelectorAll('time[datetime]').forEach(function (el) {
                el.textContent = new Date(el.dateTime).toLocaleString();
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
