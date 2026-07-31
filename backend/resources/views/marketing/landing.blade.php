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

        <title>{{ config('app.name') }} — {{ __('uptime, incident and status-page monitoring') }}</title>
        <meta name="description" content="{{ __('Monitor HTTP and TCP endpoints from pinned regions at the edge, run incidents with on-call escalation, and publish a status page your customers can subscribe to.') }}">

        <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/">

        {{-- The brand mark, not a status rendition: this page reports on nothing,
             so a green favicon here would be a status light with no subject. The
             status pages use the per-status renditions in public/favicon/. --}}
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon/operational-16.svg') }}">
        <meta name="theme-color" content="#008560" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#07090c" media="(prefers-color-scheme: dark)">

        <meta property="og:title" content="{{ config('app.name') }}">
        <meta property="og:description" content="{{ __('Uptime monitoring that refuses to guess.') }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ rtrim(config('app.url'), '/') }}/">

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
