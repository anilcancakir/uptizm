<!DOCTYPE html>
{{--
    The landing page, rebuilt section by section. Only the hero exists so far.

    Styling is Tailwind v4 against the tokens ported from the Flutter client's
    DESIGN.md (resources/css/app.css), so this surface and the product share one
    palette, type scale and radius scale. Light and dark come from the visitor's
    system preference through CSS variables rather than `dark:` pairs, which makes a
    light-only colour impossible to write rather than merely discouraged.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }}</title>

        <meta name="theme-color" content="#008560" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#07090c" media="(prefers-color-scheme: dark)">

        {{--
            Arms the entrance reveal BEFORE first paint. Doing it from the deferred
            module instead would show the content, hide it, then animate it back in.

            The failsafe is the load-bearing half: hero-monitor.js sets `motionReady`
            as soon as it can honour the hidden state, and if it never runs (blocked,
            failed to parse, offline) the class comes back off and the page is simply
            static. A page must never be able to hide its own content behind a script
            that did not arrive.
        --}}
        <script>
            (function () {
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    return;
                }

                var root = document.documentElement;
                root.classList.add('js-motion');

                window.setTimeout(function () {
                    if (root.dataset.motionReady !== '1') {
                        root.classList.remove('js-motion');
                    }
                }, 2500);
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-surface font-sans text-fg antialiased">
        <main>
            @include('marketing.hero')
        </main>
    </body>
</html>
