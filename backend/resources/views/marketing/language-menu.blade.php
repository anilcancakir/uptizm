{{--
    The language switcher, as a menu button.

    A menu rather than a pair of inline links because the list is derived from
    `magic-starter.supported_locales` and has to survive a third language without the
    header running out of room.

    Every language is a real `<a href>`, including the one already showing. That keeps
    the switcher usable with no JavaScript, lets a crawler discover the other language
    from the markup, and makes the self-link agree with the hreflang alternate that
    points at the same URL. The current one is marked with `aria-current`, not
    disabled: a disabled control is a dead end for a screen reader.
--}}
<div x-data="{ open: false }" x-on:keydown.escape="open = false" class="relative">
    <button
        type="button"
        x-on:click="open = ! open"
        :aria-expanded="open ? 'true' : 'false'"
        aria-controls="language-menu"
        aria-haspopup="true"
        class="inline-flex min-h-10 items-center gap-1.5 rounded-md px-2.5 text-label-md text-fg-muted transition-colors hover:bg-surface-container-high hover:text-fg"
    >
        {{-- A globe reads as "language" in every language, which a two-letter code
             does not: `EN` means nothing to somebody who does not read English. --}}
        <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3c2.5 2.4 2.5 15.6 0 18M12 3c-2.5 2.4-2.5 15.6 0 18" stroke-linecap="round" />
        </svg>

        <span class="sr-only">{{ __('Change language') }}</span>

        @foreach ($localeLinks as $link)
            @if ($link['current'])
                <span aria-hidden="true">{{ $link['label'] }}</span>
            @endif
        @endforeach

        <svg viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="m6 9 6 6 6-6" stroke-linecap="round" />
        </svg>
    </button>

    <ul
        id="language-menu"
        x-show="open"
        x-on:click.outside="open = false"
        x-cloak
        class="absolute right-0 z-10 mt-1 min-w-44 overflow-hidden rounded-md border border-border bg-surface-container py-1"
    >
        @foreach ($localeLinks as $link)
            <li>
                <a
                    href="{{ $link['path'] }}"
                    hreflang="{{ $link['code'] }}"
                    lang="{{ $link['code'] }}"
                    @if ($link['current']) aria-current="true" @endif
                    class="flex items-center gap-2 px-3 py-2 text-body-md text-fg-muted transition-colors hover:bg-surface-container-high hover:text-fg aria-[current=true]:text-fg"
                >
                    @if ($link['current'])
                        <svg viewBox="0 0 24 24" class="size-4 text-primary" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                            <path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    @else
                        <span class="size-4" aria-hidden="true"></span>
                    @endif

                    {{ $link['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</div>
