<!doctype html>
{{--
    Standalone shell for the public status page. This is NOT the authenticated
    app chrome: it carries its own <html>, its own Tailwind v4 build, and the
    server-side OG/meta tags a link-preview crawler reads. Every URL below is
    built from `$vm->page['slug']` via `route()`, never from the incoming
    request, so a cached response never bakes in a stale or spoofed host.
--}}
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $vm->page['name'] }}</title>

        <meta property="og:title" content="{{ $vm->page['name'] }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('status.show', $vm->page['slug']) }}">
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
