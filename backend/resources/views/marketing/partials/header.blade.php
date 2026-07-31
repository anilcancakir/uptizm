{{--
    Sticky site header. One primary CTA, repeated verbatim wherever it appears:
    a page that varies its verb ("Get started" here, "Try it" there) reads as
    three different offers.

    Alpine drives the narrow-viewport disclosure only; there is no other client
    state on this page.
--}}
<header
    x-data="{ open: false }"
    class="sticky top-0 z-50 border-b border-border bg-surface"
>
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3.5 sm:px-6 lg:px-8">
        <a href="/" class="flex items-center gap-2.5 text-fg">
            @include('marketing.partials.mark', ['class' => 'size-6 text-primary'])
            <span class="text-title-lg tracking-tight">{{ config('app.name') }}</span>
        </a>

        <nav class="hidden items-center gap-1 md:flex">
            @foreach ([
                '#pipeline' => __('How it works'),
                '#signal' => __('Signal'),
                '#capabilities' => __('Capabilities'),
                '#regions' => __('Regions'),
            ] as $href => $label)
                <a
                    href="{{ $href }}"
                    class="rounded-base px-3 py-2 text-body-md text-fg-muted transition-colors hover:bg-surface-container-high hover:text-fg"
                >{{ $label }}</a>
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            <a
                href="{{ $signInUrl }}"
                class="hidden rounded-base px-3 py-2 text-body-md text-fg-muted transition-colors hover:text-fg sm:inline-flex"
            >{{ __('Sign in') }}</a>

            <a
                href="{{ $signUpUrl }}"
                class="inline-flex min-h-11 items-center rounded-md bg-primary px-4 text-label-md text-on-primary transition-opacity hover:opacity-90"
            >{{ __('Start free') }}</a>

            <button
                type="button"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open"
                aria-controls="site-nav"
                class="inline-flex size-11 items-center justify-center rounded-base text-fg-muted transition-colors hover:bg-surface-container-high hover:text-fg md:hidden"
            >
                <span class="sr-only">{{ __('Toggle navigation') }}</span>
                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                    <path x-show="! open" stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                    <path x-show="open" x-cloak stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    <nav id="site-nav" x-show="open" x-cloak class="border-t border-border bg-surface md:hidden">
        <div class="mx-auto flex max-w-6xl flex-col p-2 sm:px-4">
            @foreach ([
                '#pipeline' => __('How it works'),
                '#signal' => __('Signal'),
                '#capabilities' => __('Capabilities'),
                '#regions' => __('Regions'),
                $signInUrl => __('Sign in'),
            ] as $href => $label)
                <a
                    href="{{ $href }}"
                    x-on:click="open = false"
                    class="rounded-base px-3 py-3 text-body-lg text-fg-muted transition-colors hover:bg-surface-container-high hover:text-fg"
                >{{ $label }}</a>
            @endforeach
        </div>
    </nav>
</header>
