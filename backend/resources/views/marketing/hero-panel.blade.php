@php
    /*
     * Baselines for the hero panel, keyed by the region values the MonitorRegion
     * enum defines. `colo` is the Cloudflare data centre a probe for that region
     * actually came back from during live verification, so the geography is real
     * even though the latency is an example.
     *
     * These are what the page RENDERS. resources/js/hero-monitor.js jitters around
     * them to drive the panel as a running monitor; without that script the panel
     * simply sits on these, which is a correct quiet state rather than a broken one.
     *
     * A region with no entry renders "no data" in the neutral `paused` tone and takes
     * no part in the simulation, which is what the product does with a period it has
     * no checks for. Adding a region to the enum therefore degrades this panel into
     * honesty rather than into a fabricated row.
     */
    $baselines = [
        'us-east' => ['colo' => 'MIA', 'ms' => 36],
        'us-west' => ['colo' => 'DFW', 'ms' => 52],
        'eu-west' => ['colo' => 'CDG', 'ms' => 12],
        'eu-central' => ['colo' => 'FRA', 'ms' => 18],
        'ap' => ['colo' => 'HKG', 'ms' => 88],
    ];

    // A bar reaches full width at this latency; anything slower pins to 1.
    $scaleMs = 110;

    /*
     * 90 days of example history. Two days are deliberately not green, one degraded
     * and one with NO DATA, because that is what the real strip renders for a period
     * a monitor was not being checked, and a wall of flawless green would advertise a
     * product that cannot express doubt. The last day is today, still in progress, so
     * the simulation moves it with the current verdict.
     */
    $history = collect(range(1, 90))->map(fn (int $d): string => match (true) {
        $d === 62 => 'degraded',
        $d === 74 => 'none',
        default => 'up',
    });
@endphp

{{-- role="img" with a label naming this an example: the panel is decorative
     evidence, and a screen reader should get the summary rather than a table of
     numbers that change every few seconds. --}}
<div
    role="img"
    aria-label="{{ __('Example: an Uptizm monitor checking one endpoint from every region at once.') }}"
    data-monitor
    data-scale-ms="{{ $scaleMs }}"
    data-enter
    style="--enter-index: 3"
    class="overflow-hidden rounded-lg border border-border bg-surface-container"
>
    {{-- Window chrome. The three dots are NEUTRAL: real traffic lights are red,
         amber and green, and on this page those three mean down, degraded and up. --}}
    <div class="flex items-center gap-3 border-b border-border-subtle px-4 py-3">
        <span class="flex gap-1.5" aria-hidden="true">
            <span class="size-2.5 rounded-full bg-fg-disabled"></span>
            <span class="size-2.5 rounded-full bg-fg-disabled"></span>
            <span class="size-2.5 rounded-full bg-fg-disabled"></span>
        </span>
        <span class="min-w-0 flex-1 truncate rounded-base bg-surface-container-high px-3 py-1 text-center font-mono text-label-sm text-fg-muted">
            {{ config('app.name') }} &middot; {{ __('monitor') }}
        </span>
    </div>

    <div data-verdict-scope class="flex items-start justify-between gap-4 border-b border-border-subtle p-5">
        <div class="min-w-0">
            <div class="flex items-center gap-2.5">
                <span data-breathe data-verdict-dot class="size-2.5 shrink-0 rounded-full bg-up"></span>
                <span class="truncate font-mono text-body-md text-fg">api.acme.com</span>
            </div>
            <p class="mt-1.5 pl-[1.25rem] text-label-sm text-fg-muted">
                {{ __('HTTP · :count regions, checked together', ['count' => count($regions)]) }}
            </p>
        </div>

        {{-- Labels ride on the element so the simulation carries no copy of its own
             and stays translatable. --}}
        <span
            data-verdict
            data-state="up"
            data-label-up="{{ __('Operational') }}"
            data-label-degraded="{{ __('Degraded') }}"
            data-label-down="{{ __('Major outage') }}"
            class="shrink-0 rounded-full px-2.5 py-1 text-label-sm"
        >{{ __('Operational') }}</span>
    </div>

    <ul class="divide-y divide-border-subtle">
        @foreach ($regions as $region)
            @php $b = $baselines[$region['value']] ?? null; @endphp

            <li
                @if ($b !== null)
                    data-region
                    data-state="up"
                    data-baseline-ms="{{ $b['ms'] }}"
                    data-label-timeout="{{ __('timeout') }}"
                @endif
                class="relative isolate flex items-center gap-3 px-5 py-3"
            >
                @if ($b !== null)
                    <span class="relative flex size-2 shrink-0">
                        <span data-ring class="absolute inline-flex size-full rounded-full bg-up"></span>
                        <span data-dot class="relative inline-flex size-2 rounded-full bg-up"></span>
                    </span>
                @else
                    <span class="size-2 shrink-0 rounded-full bg-paused"></span>
                @endif

                <span class="w-24 shrink-0 truncate text-body-md text-fg">{{ $region['label'] }}</span>
                <span class="w-9 shrink-0 font-mono text-label-sm text-fg-muted">{{ $b['colo'] ?? '—' }}</span>

                {{-- The fill is full width and SCALED on the X axis. Animating `width`
                     would relayout the row on every frame; a transform does not. --}}
                <span class="hidden h-1 flex-1 overflow-hidden rounded-full bg-surface-container-high sm:block">
                    @if ($b !== null)
                        <span
                            data-bar-fill
                            class="block h-full w-full origin-left rounded-full"
                            style="transform: scaleX({{ round(min(1, $b['ms'] / $scaleMs), 3) }})"
                        ></span>
                    @endif
                </span>

                @if ($b !== null)
                    <span data-ms class="w-16 shrink-0 text-right font-mono text-label-sm tabular-nums">{{ $b['ms'] }} ms</span>
                @else
                    <span class="w-16 shrink-0 text-right font-mono text-label-sm tabular-nums text-fg-muted">{{ __('no data') }}</span>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="border-t border-border-subtle p-5">
        <div class="flex items-baseline justify-between">
            <span class="text-label-sm text-fg-muted">{{ __('Last 90 days') }}</span>
            <span class="font-mono text-label-sm text-fg-muted">{{ __('1 degraded · 1 no data') }}</span>
        </div>

        <div class="mt-2.5 flex h-6 items-stretch gap-px">
            @foreach ($history as $day)
                {{-- `data-state` drives the colour from CSS, not a Tailwind class,
                     because the last segment changes from JS and Tailwind only
                     generates classes it can see in the source. --}}
                <span
                    data-day
                    data-state="{{ $day }}"
                    @if ($loop->last) data-today @endif
                    class="flex-1 rounded-[1px]"
                ></span>
            @endforeach
        </div>
    </div>
</div>
