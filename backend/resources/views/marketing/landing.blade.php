<!DOCTYPE html>
{{--
    The marketing landing page for the apex host, served by
    App\Http\Controllers\Marketing\ShowLandingController.

    One rule governs this whole directory: A CLAIM IS DERIVED, NEVER TYPED.
    Region names come from the MonitorRegion enum, alert destinations from
    NotificationChannelType, the free-tier numbers from the plan catalog PlanGate
    enforces, and the two capability-dependent sections from whether this
    deployment can actually do the thing:

      - the AI section renders only when an AI provider key is present, because
        without one every AI path returns its deterministic fallback
      - the email-subscriber promise renders only when the mailer can deliver,
        because with MAIL_MAILER=log the confirmation mail goes to a file

    That is not defensive decoration. It means a misconfigured deployment
    advertises less rather than lying, and the page corrects itself the moment the
    capability is switched on.

    Styling is Tailwind v4 against the tokens ported from the Flutter client's
    DESIGN.md (resources/css/app.css), so this surface and the product share one
    type scale, one palette and one radius scale. Light and dark come from the
    visitor's system preference through CSS variables rather than `dark:` pairs.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Title, description, canonical, robots, Open Graph, Twitter card and
             the SoftwareApplication JSON-LD, all set in ShowLandingController so
             the crawler-facing copy sits next to the page's own claims and cannot
             drift from them. --}}
        {!! SEO::generate() !!}

        {{-- Icons carry the BRAND mark, not a status rendition: this page reports
             on nothing, so a green status light here would have no subject. The
             per-status renditions in public/favicon/ belong to the status pages.

             Three formats because they answer different clients: the SVG (which
             carries its own dark-mode pair) for current browsers, the .ico for
             the bare /favicon.ico that crawlers and older clients request, and an
             opaque PNG for iOS, which composites the home-screen icon with no
             transparency handling. --}}
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="manifest" href="{{ asset('site.webmanifest') }}">

        <meta name="theme-color" content="#008560" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#07090c" media="(prefers-color-scheme: dark)">

        {{-- Preload the sans face only. It renders the hero headline, which is
             the largest contentful paint; the mono face carries the numbers and
             can arrive a beat later without moving the layout that gets measured.
             Preloading both would spend 140KB of early bandwidth to save nothing.
             --}}
        <link
            rel="preload"
            as="font"
            type="font/woff2"
            href="{{ Vite::asset('resources/fonts/GeistVariable.woff2') }}"
            crossorigin
        >

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            [x-cloak] { display: none !important; }

            /*
             * One orchestrated entrance for the hero, staggered by depth, rather
             * than a scroll-triggered effect on every section. Skipped entirely
             * under reduced-motion: the content is already in place, so there is
             * nothing to fall back to.
             */
            @media (prefers-reduced-motion: no-preference) {
                [data-enter] {
                    animation: enter 0.5s cubic-bezier(0.22, 1, 0.36, 1) backwards;
                }

                @keyframes enter {
                    from {
                        opacity: 0;
                        transform: translateY(0.75rem);
                    }
                }
            }

            /* Anchor targets sit below the sticky header rather than under it. */
            [id] {
                scroll-margin-top: 4.5rem;
            }
        </style>
    </head>
    <body class="min-h-screen bg-surface font-sans text-fg antialiased">
        @include('marketing.partials.header')

        <main>
            @include('marketing.partials.hero')
            @include('marketing.partials.pipeline')
            @include('marketing.partials.signal')
            @include('marketing.partials.capabilities')
            @include('marketing.partials.metrics')
            @include('marketing.partials.status-pages')

            @if ($aiEnabled)
                @include('marketing.partials.ai')
            @endif

            @include('marketing.partials.regions')
            @include('marketing.partials.cta')
        </main>

        @include('marketing.partials.footer')
    </body>
</html>
