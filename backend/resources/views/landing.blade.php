<!DOCTYPE html>
{{--
    The landing page, rebuilt section by section.

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

        {{-- The hero shows a short line per act now, so the sentence that describes the
             product no longer sits in the body copy. It lives here, which is where a
             summary belongs anyway: a crawler and a link preview both read it, and a
             six-word slogan would have left them nothing. --}}
        <meta name="description" content="{{ $summary }}">

        <meta name="theme-color" content="#008560" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#07090c" media="(prefers-color-scheme: dark)">

        {{--
            One canonical URL per language, and every language declared as an
            alternate of every other.

            The locale is carried by the path alone: `/` for the default language,
            `/<code>` for each of the others. No redirect on Accept-Language, which
            is the opposite of what the Flutter client does. The client can negotiate
            freely because nothing indexes it; a public page that redirects on browser
            language shows a crawler only one of its languages and traps a visitor who
            wants the other one.

            `x-default` is the fallback for a visitor whose language we do not speak,
            and it points at the apex.
        --}}
        <link rel="canonical" href="{{ $canonicalUrl }}">

        @foreach ($localeLinks as $link)
            <link rel="alternate" hreflang="{{ $link['code'] }}" href="{{ $link['url'] }}">
        @endforeach

        <link rel="alternate" hreflang="x-default" href="{{ route('landing') }}">

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
        {{-- The header is sticky, so it sits ahead of the content on every Tab pass.
             This is what makes that survivable. Visually hidden until focused. --}}
        <a
            href="#content"
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[60] focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-label-md focus:text-on-primary"
        >{{ __('Skip to content') }}</a>

        @include('marketing.header')

        <main id="content">
            @include('marketing.hero')
            @include('marketing.decides')
        </main>

        @include('marketing.footer')
    </body>
</html>
