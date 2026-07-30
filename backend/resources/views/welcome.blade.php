<!DOCTYPE html>
{{--
    The marketing landing page for the apex host.

    Deliberately plain: Tailwind's default gray scale plus the one brand token
    from resources/css/app.css, matching how the public status page styles
    itself, so there is no second design system to maintain here. Alpine drives
    the mobile nav only.

    Every claim below describes a capability that exists in this codebase, and
    the region list is read from `config/relay.php` rather than written out, so
    the page cannot drift into advertising regions we do not probe from.

    This replaces Laravel's default welcome screen so the apex host is a real
    page in production; a fuller marketing site supersedes it later.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }} — uptime, incident and status-page monitoring</title>
        <meta name="description" content="Monitor HTTP and TCP endpoints from multiple regions, run incidents with on-call escalation, and publish a status page your customers can subscribe to.">

        <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/">

        <meta property="og:title" content="{{ config('app.name') }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ rtrim(config('app.url'), '/') }}/">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            [x-cloak] { display: none !important; }
        </style>
    </head>
    <body class="min-h-screen bg-white text-gray-900 antialiased">
        @php
            $appUrl = rtrim(config('app.frontend_url'), '/');
            $regions = config('relay.regions', []);
        @endphp

        {{-- Header --}}
        <header x-data="{ open: false }" class="border-b border-gray-200">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                <a href="/" class="flex items-center gap-2 font-semibold tracking-tight">
                    <span class="flex size-7 items-center justify-center rounded-md bg-brand text-sm font-bold text-white">U</span>
                    <span>{{ config('app.name') }}</span>
                </a>

                <nav class="hidden items-center gap-8 text-sm text-gray-600 sm:flex">
                    <a href="#capabilities" class="hover:text-gray-900">Capabilities</a>
                    <a href="#regions" class="hover:text-gray-900">Regions</a>
                    <a href="{{ $appUrl }}/login" class="hover:text-gray-900">Sign in</a>
                    <a href="{{ $appUrl }}/register" class="rounded-md bg-brand px-3 py-2 font-medium text-white hover:bg-brand-dark">
                        Get started
                    </a>
                </nav>

                <button
                    type="button"
                    x-on:click="open = !open"
                    x-bind:aria-expanded="open"
                    aria-controls="mobile-nav"
                    class="rounded-md p-2 text-gray-600 hover:bg-gray-100 sm:hidden"
                >
                    <span class="sr-only">Toggle navigation</span>
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                    </svg>
                </button>
            </div>

            <nav id="mobile-nav" x-show="open" x-cloak class="border-t border-gray-200 sm:hidden">
                <div class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-3 text-sm">
                    <a href="#capabilities" class="rounded-md px-2 py-2 text-gray-600 hover:bg-gray-100">Capabilities</a>
                    <a href="#regions" class="rounded-md px-2 py-2 text-gray-600 hover:bg-gray-100">Regions</a>
                    <a href="{{ $appUrl }}/login" class="rounded-md px-2 py-2 text-gray-600 hover:bg-gray-100">Sign in</a>
                    <a href="{{ $appUrl }}/register" class="mt-1 rounded-md bg-brand px-3 py-2 text-center font-medium text-white">Get started</a>
                </div>
            </nav>
        </header>

        {{-- Hero --}}
        <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
            <div class="max-w-2xl">
                <h1 class="text-4xl font-bold tracking-tight text-gray-900 sm:text-5xl">
                    Know it is down before your customers tell you.
                </h1>
                <p class="mt-6 text-lg text-gray-600">
                    {{ config('app.name') }} checks your HTTP and TCP endpoints from multiple
                    regions, opens an incident when they fail, escalates it to whoever is on
                    call, and keeps a public status page honest for the people waiting on
                    you.
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <a href="{{ $appUrl }}/register" class="rounded-md bg-brand px-5 py-3 text-sm font-medium text-white hover:bg-brand-dark">
                        Start monitoring
                    </a>
                    <a href="{{ $appUrl }}/login" class="rounded-md border border-gray-300 px-5 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Sign in
                    </a>
                </div>
            </div>
        </section>

        {{-- Capabilities --}}
        <section id="capabilities" class="border-t border-gray-200 bg-gray-50">
            <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
                <h2 class="text-2xl font-semibold tracking-tight">What it does</h2>

                <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        [
                            'title' => 'Multi-region checks',
                            'body' => 'HTTP and TCP probes run at the edge from every region you enable, so one unhealthy network path does not read as a global outage.',
                        ],
                        [
                            'title' => 'Incidents and on call',
                            'body' => 'A failing monitor opens an incident with a timeline. Escalation policies page the right responder over push, Slack, PagerDuty, Teams, webhook or SMS.',
                        ],
                        [
                            'title' => 'Status pages',
                            'body' => 'Publish the components you choose, on a path, on a subdomain, or on your own domain. Customers subscribe by email and hear about it when it changes.',
                        ],
                        [
                            'title' => 'AI-assisted triage',
                            'body' => 'Suggested severity, impact and a first summary drawn from the check evidence. It proposes, a human decides, and the suggestion stays labelled as one.',
                        ],
                    ] as $capability)
                        <article class="rounded-lg border border-gray-200 bg-white p-6">
                            <h3 class="font-semibold">{{ $capability['title'] }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ $capability['body'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Regions, read from configuration so the page cannot overstate them --}}
        @if ($regions !== [])
            <section id="regions" class="border-t border-gray-200">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:px-8">
                    <h2 class="text-2xl font-semibold tracking-tight">Where checks run from</h2>
                    <p class="mt-3 max-w-2xl text-gray-600">
                        Probes execute in region-pinned edge workers. These are the regions
                        currently available to select on a monitor.
                    </p>
                    <ul class="mt-8 flex flex-wrap gap-3">
                        @foreach ($regions as $region)
                            <li class="rounded-full border border-gray-200 bg-gray-50 px-4 py-1.5 font-mono text-sm text-gray-700">
                                {{ $region }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        {{-- Closing call to action --}}
        <section class="border-t border-gray-200 bg-gray-900">
            <div class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 px-4 py-14 sm:flex-row sm:items-center sm:px-6 lg:px-8">
                <div>
                    <h2 class="text-xl font-semibold text-white">Put your first monitor up in a minute.</h2>
                    <p class="mt-2 text-sm text-gray-400">A URL and a check interval is all it takes to start.</p>
                </div>
                <a href="{{ $appUrl }}/register" class="rounded-md bg-brand px-5 py-3 text-sm font-medium text-white hover:bg-brand-dark">
                    Create an account
                </a>
            </div>
        </section>

        <footer class="border-t border-gray-200">
            <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-8 text-sm text-gray-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                <p>&copy; {{ now()->year }} {{ config('app.name') }}</p>
                <nav class="flex gap-6">
                    <a href="{{ $appUrl }}/login" class="hover:text-gray-900">Sign in</a>
                    <a href="#capabilities" class="hover:text-gray-900">Capabilities</a>
                </nav>
            </div>
        </footer>
    </body>
</html>
