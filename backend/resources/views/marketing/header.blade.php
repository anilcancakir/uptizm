{{--
    The header.

    Sticky, and transparent until the page moves. A bar that is opaque from the first
    pixel puts a hard edge across the hero for no reason; once there is content behind
    it, it needs the background to stay legible. The switch is a colour change, not a
    movement, so it is safe under reduced motion.

    The nav renders from `$sections`, which lists only sections that exist on the page.
    While the hero is the only one, there are no nav links at all, and that is correct:
    a header link to a section that is not built yet is a link that does nothing.
--}}
<header
    x-data="{ open: false, scrolled: false }"
    x-on:scroll.window="scrolled = window.scrollY > 8"
    x-on:keydown.escape="open = false"
    class="sticky top-0 z-50 border-b transition-colors"
    :class="scrolled || open ? 'border-border bg-surface' : 'border-transparent'"
>
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4 sm:px-6 lg:px-8">
        <a
            href="{{ $homePath }}"
            class="flex items-center gap-2.5 rounded-base transition-opacity hover:opacity-80"
        >
            @include('marketing.brand-mark')
            <span class="text-title-lg text-fg">{{ config('app.name') }}</span>
        </a>

        @if ($sections !== [])
            <nav class="ml-6 hidden items-center gap-1 lg:flex" aria-label="{{ __('Sections') }}">
                @foreach ($sections as $section)
                    <a
                        href="#{{ $section['id'] }}"
                        class="rounded-base px-3 py-2 text-label-md text-fg-muted transition-colors hover:text-fg"
                    >{{ $section['label'] }}</a>
                @endforeach
            </nav>
        @endif

        <div class="ml-auto flex items-center gap-2">
            {{-- Hidden on the narrowest screens, where the bar has room for the brand,
                 one call to action and the menu button and nothing else. The mobile
                 panel carries the full language list instead. --}}
            <div class="hidden sm:block">
                @include('marketing.language-menu')
            </div>

            <a
                href="{{ $signInUrl }}"
                class="hidden min-h-10 items-center rounded-md px-3 text-label-md text-fg-muted transition-colors hover:bg-surface-container-high hover:text-fg sm:inline-flex"
            >{{ __('Sign in') }}</a>

            <a
                href="{{ $signUpUrl }}"
                class="inline-flex min-h-10 items-center rounded-md bg-primary px-4 text-label-md text-on-primary transition-opacity hover:opacity-90"
            >{{ __('Start free') }}</a>

            {{-- The menu button disappears at the width where the bar already shows
                 everything the panel holds. With no sections built yet the panel is just
                 Sign in plus the languages, and both of those are in the bar from `sm`
                 up, so above `sm` the button would open a drawer of duplicates. Once
                 there ARE sections it earns its place all the way to `lg`. --}}
            <button
                type="button"
                x-on:click="open = ! open"
                :aria-expanded="open ? 'true' : 'false'"
                aria-controls="mobile-menu"
                class="-mr-1 inline-flex size-10 items-center justify-center rounded-md text-fg-muted transition-colors hover:bg-surface-container-high hover:text-fg {{ $sections === [] ? 'sm:hidden' : 'lg:hidden' }}"
            >
                {{-- The label follows the state, because the icon alone does not
                     announce whether the panel is open. --}}
                <span class="sr-only" x-text="open ? '{{ __('Close menu') }}' : '{{ __('Menu') }}'">{{ __('Menu') }}</span>

                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path x-show="! open" d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round" />
                    <path x-show="open" x-cloak d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                </svg>
            </button>
        </div>
    </div>

    {{-- The disclosure panel. Everything the bar drops at narrow widths lives here,
         so nothing is only reachable on a wide screen. --}}
    <div id="mobile-menu" x-show="open" x-cloak class="border-t border-border bg-surface {{ $sections === [] ? 'sm:hidden' : 'lg:hidden' }}">
        <div class="mx-auto max-w-6xl px-4 py-4 sm:px-6">
            @if ($sections !== [])
                <nav class="flex flex-col" aria-label="{{ __('Sections') }}">
                    @foreach ($sections as $section)
                        <a
                            href="#{{ $section['id'] }}"
                            x-on:click="open = false"
                            class="rounded-base px-2 py-3 text-body-lg text-fg-muted transition-colors hover:text-fg"
                        >{{ $section['label'] }}</a>
                    @endforeach
                </nav>
            @endif

            <a
                href="{{ $signInUrl }}"
                class="block rounded-base px-2 py-3 text-body-lg text-fg-muted transition-colors hover:text-fg sm:hidden"
            >{{ __('Sign in') }}</a>

            <p class="mt-3 px-2 text-label-sm uppercase tracking-[0.12em] text-fg-muted">{{ __('Language') }}</p>

            <div class="mt-1 flex flex-col">
                @foreach ($localeLinks as $link)
                    <a
                        href="{{ $link['path'] }}"
                        hreflang="{{ $link['code'] }}"
                        lang="{{ $link['code'] }}"
                        @if ($link['current']) aria-current="true" @endif
                        class="rounded-base px-2 py-3 text-body-lg text-fg-muted transition-colors hover:text-fg aria-[current=true]:text-fg"
                    >{{ $link['label'] }}</a>
                @endforeach
            </div>
        </div>
    </div>
</header>
