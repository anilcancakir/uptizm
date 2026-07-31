<!DOCTYPE html>
{{--
    The shell every marketing page renders inside: the head, the skip link, the header,
    the main region and the footer. Extracted from landing.blade.php when the legal and
    support pages arrived, because the alternative was four copies of an hreflang set.

    Styling is Tailwind v4 against the tokens ported from the Flutter client's DESIGN.md
    (resources/css/app.css), so this surface and the product share one palette, type scale
    and radius scale. Light and dark come from the visitor's system preference through CSS
    variables rather than `dark:` pairs, which makes a light-only colour impossible to
    write rather than merely discouraged.

    WHAT A PAGE MUST SUPPLY

    Everything in `App\Support\Marketing\ChromeData`, spread into its view data. The
    partials below dereference their variables UNGUARDED (`count($regions)`,
    `$platformClaim`, `$summary`, `$canonicalUrl`), so a page that forgets one throws
    rather than rendering a shell with a hole in it. Never hand-pick that list: build a
    ChromeData. `tests/Feature/Marketing/LayoutTest.php` renders a page from nothing else
    and is what keeps the contract honest as the chrome grows.

    NOTHING HERE NEEDS A SESSION

    No `@csrf`, no `csrf_token()`, no `old()`, no `@error`, no `session()`. These routes
    are registered outside the `web` group precisely so they set no cookie, and the
    Privacy page publishes that as a claim. See routes/marketing.php for the full
    reasoning; a form on a page using this layout is a legal problem, not a preference.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- The brand alone for the home page, and the page's own name in front of it
             everywhere else: a browser tab, a bookmark and a search result all show the
             beginning of this string, so the distinguishing half goes first. --}}
        <title>@yield('title', config('app.name'))</title>

        {{-- Every page describes ITSELF here. A crawler and a link preview both read it,
             and the hero shows a short line per act rather than a paragraph, so this is
             the only place the landing page's full sentence survives at all. A page
             reusing another page's summary tells a crawler they are the same document. --}}
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

            All three of these are THIS page's URLs, composed by ChromeData from the
            page's own path. They used to name the landing route directly, which was
            correct while the landing page was the only page and became a lie the moment
            a second one rendered through the same head: every legal page would have
            declared itself a duplicate of the home page.

            `x-default` is the fallback for a visitor whose language we do not speak, and
            it points at this page in the default language rather than at the apex.
        --}}
        <link rel="canonical" href="{{ $canonicalUrl }}">

        @foreach ($localeLinks as $link)
            <link rel="alternate" hreflang="{{ $link['code'] }}" href="{{ $link['url'] }}">
        @endforeach

        <link rel="alternate" hreflang="x-default" href="{{ $defaultLocaleUrl }}">

        {{--
            Arms the entrance reveal BEFORE first paint. Doing it from the deferred
            module instead would show the content, hide it, then animate it back in.

            It arms regardless of the motion preference, and that is the fix for a real
            defect: this used to return early on `prefers-reduced-motion: reduce`, so
            `js-motion` was never set, so every reveal below the hero was dead for anybody
            with the preference on. The class means "JavaScript is here and can honour a
            hidden state" and nothing else; app.css decides whether the arrival is a slide
            or a cross-fade.

            The failsafe is the load-bearing half: app.js sets `motionReady` as soon as it
            can honour the hidden state, and if it never runs (blocked, failed to parse,
            offline) the class comes back off and the page is simply static. A page must
            never be able to hide its own content behind a script that did not arrive.
        --}}
        <script>
            (function () {
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
            @yield('content')
        </main>

        @include('marketing.footer')
    </body>
</html>
