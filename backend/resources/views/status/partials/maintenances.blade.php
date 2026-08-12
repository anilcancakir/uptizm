{{--
    Planned maintenance: every window announced on this page that is open right
    now or starts inside the assembler's forward horizon, earliest first. It sits
    ABOVE the components so a visitor arriving mid-window reads "this was
    announced" before reading the red it explains.

    Unlike the components and incidents cards, this section has no standing empty
    state. Those two describe the service permanently, so an empty card there is
    still an answer; planned work is the exception, and an "no maintenance
    scheduled" card would push the actual health of the service down the page
    every day of the year nothing is planned.

    The assembler has already decided WHICH windows a visitor may see (the page
    they were announced on, intersected with its visible monitors) and whether
    each one is in progress. Nothing here queries, and nothing here asks the
    clock: the payload is cached, so a template reading `now()` would disagree
    with the snapshot it was rendered from.
--}}
@php
    // The language every announced window was authored in. `scheduled_maintenances`
    // carries no language column, so the write and the read side both resolve it
    // from `app.default_locale`; see the same expression in partials/incidents.blade.php.
    $sourceLocale = (string) config('app.default_locale');

    /*
     * The state badge, on the status vocabulary's soft tones, same pairing the
     * incident lifecycle badge uses.
     *
     * `in_progress` takes `info`: work under way is a fact about the service the
     * visitor should read as informational, not as a fault, and it must never
     * borrow `down` (this is the one outage nobody needs to report). An upcoming
     * window is NEUTRAL: nothing is happening yet, so the page has nothing to
     * characterise. Anything unrecognised lands there too, one step short of a
     * claim rather than inside one.
     */
    $stateBadgeClass = static fn (string $state): string => match ($state) {
        'in_progress' => 'bg-info-soft text-info-soft-foreground',
        default => 'bg-paused-soft text-paused-soft-foreground',
    };

    $stateLabel = static fn (string $state): string => match ($state) {
        'in_progress' => (string) __('status.maintenances.state.in_progress'),
        default => (string) __('status.maintenances.state.scheduled'),
    };
@endphp

@if ($vm->maintenances !== [])
    <section class="mb-6 rounded-lg border border-border bg-surface-container">
        <h2 class="border-b border-border px-5 py-3 text-sm font-semibold text-fg-muted uppercase">{{ __('status.maintenances.heading') }}</h2>

        <ul class="divide-y divide-border-subtle">
            @foreach ($vm->maintenances as $window)
                <li class="px-5 py-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ $window['title'] }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $stateBadgeClass($window['state']) }}">
                            {{ $stateLabel($window['state']) }}
                        </span>
                    </div>

                    {{-- Under the title row, not inside it: the row is a flex line
                         carrying the title and the state badge. --}}
                    @include('status.partials.translation-note', [
                        'provenance' => $window['title_provenance'],
                        'original' => $window['title_original'],
                        'sourceLocale' => $sourceLocale,
                    ])

                    {{-- Both bounds as `<time datetime>`, which the layout rewrites to the
                         viewer's own zone. The server-rendered text is the no-JS fallback
                         and carries its zone, so it is never read as local by mistake.
                         `translatedFormat` under the page's locale for the same reason the
                         incident stamp uses it: a bare `format('M ...')` puts an English
                         month abbreviation between two Turkish words, and the reader who
                         sees this text is precisely the one with no script to fix it. --}}
                    <p class="mt-1 text-xs text-fg-muted tabular-nums">
                        <time datetime="{{ $window['startsAt'] }}">
                            {{ \Illuminate\Support\Carbon::parse($window['startsAt'])->locale(app()->getLocale())->translatedFormat('M j, Y H:i T') }}
                        </time>
                        {{ __('status.maintenances.range_separator') }}
                        <time datetime="{{ $window['endsAt'] }}">
                            {{ \Illuminate\Support\Carbon::parse($window['endsAt'])->locale(app()->getLocale())->translatedFormat('M j, Y H:i T') }}
                        </time>
                    </p>

                    @if (($window['description'] ?? '') !== '')
                        <p class="mt-2 text-sm whitespace-pre-line text-fg">{{ $window['description'] }}</p>

                        @include('status.partials.translation-note', [
                            'provenance' => $window['description_provenance'],
                            'original' => $window['description_original'],
                            'sourceLocale' => $sourceLocale,
                        ])
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
