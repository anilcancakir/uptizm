@php
    /*
     * Baselines for the hero panel, keyed by the region values the MonitorRegion
     * enum defines. `colo` is the Cloudflare data centre a probe for that region
     * actually came back from during live verification, so the geography is real
     * even though the latency is an example.
     *
     * These are the values the page RENDERS. resources/js/monitor-sim.js then
     * jitters around them to drive the panel as a running monitor; without that
     * script the panel simply sits on these, which is a correct quiet state rather
     * than a broken one.
     *
     * A region with no entry renders as "no data" in the neutral `paused` tone and
     * takes no part in the simulation. That is deliberate: it is what the product
     * does with a period it has no checks for, so adding a region to the enum
     * degrades this panel into honesty rather than into a fabricated row.
     */
    $exampleTelemetry = [
        'us-east' => ['colo' => 'MIA', 'ms' => 36],
        'us-west' => ['colo' => 'DFW', 'ms' => 52],
        'eu-west' => ['colo' => 'CDG', 'ms' => 12],
        'eu-central' => ['colo' => 'FRA', 'ms' => 18],
        'ap' => ['colo' => 'HKG', 'ms' => 88],
    ];

    // Full-width bar at this latency; anything slower simply pins to 100%.
    $latencyScaleMs = 110;

    /*
     * 90 days of an example history. Two days are deliberately not green: one
     * degraded and one with NO DATA, because that is what the real history strip
     * renders when a monitor was not being checked, and a hero showing a flawless
     * wall of green would be advertising a product that cannot express doubt.
     *
     * The last day is today, still in progress, so the simulation moves it with
     * the monitor's current verdict.
     */
    $historyDays = collect(range(1, 90))
        ->map(fn (int $day): string => match (true) {
            $day === 62 => 'degraded',
            $day === 74 => 'none',
            default => 'up',
        });
@endphp

{{-- role="img" with a label naming this an example: the panel is decorative
     evidence, and a screen reader should get the summary rather than a table of
     numbers that change every two seconds. --}}
<div
    role="img"
    aria-label="{{ __('Example: an Uptizm monitor showing one check result per probe region.') }}"
    data-monitor-sim
    data-scale-ms="{{ $latencyScaleMs }}"
    data-enter
    style="animation-delay: 280ms"
    class="overflow-hidden rounded-lg border border-border bg-surface-container"
>
    {{-- Window chrome. Neutral dots, not traffic lights: red, amber and green
         mean down, degraded and up everywhere else on this page. --}}
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

    {{-- Fills between checks and resets on each one, so the panel shows that the
         next check is COMING rather than only that the last one arrived. --}}
    <div class="h-0.5 bg-surface-container-high" aria-hidden="true">
        <div data-check-progress class="h-full w-0 bg-primary/60"></div>
    </div>

    <div class="flex items-start justify-between gap-4 border-b border-border-subtle p-5">
        <div class="min-w-0">
            <div class="flex items-center gap-2.5">
                <span data-rollup-dot class="size-2.5 shrink-0 rounded-full"></span>
                <span class="truncate font-mono text-body-md text-fg">api.acme.com</span>
            </div>
            <p class="mt-1.5 pl-[1.25rem] text-label-sm text-fg-muted">
                {{ __('HTTP · every 30s · :count regions', ['count' => count($regions)]) }}
            </p>
        </div>

        {{-- Labels ride on the element so the simulation carries no copy of its
             own and stays translatable. --}}
        <span
            data-rollup
            data-state="up"
            data-label-up="{{ __('Operational') }}"
            data-label-degraded="{{ __('Degraded') }}"
            data-label-down="{{ __('Major outage') }}"
            class="shrink-0 rounded-full px-2.5 py-1 text-label-sm"
        >
            <span data-rollup-label>{{ __('Operational') }}</span>
        </span>
    </div>

    <ul class="divide-y divide-border-subtle">
        @foreach ($regions as $region)
            @php $sample = $exampleTelemetry[$region['value']] ?? null; @endphp

            <li
                @if ($sample !== null)
                    data-region
                    data-state="up"
                    data-baseline-ms="{{ $sample['ms'] }}"
                    data-label-timeout="{{ __('timeout') }}"
                @endif
                class="relative flex items-center gap-3 px-5 py-3"
            >
                @if ($sample !== null)
                    <span class="relative flex size-2 shrink-0">
                        <span data-region-ring class="absolute inline-flex size-full rounded-full"></span>
                        <span data-region-dot class="relative inline-flex size-2 rounded-full"></span>
                    </span>
                @else
                    <span class="size-2 shrink-0 rounded-full bg-paused"></span>
                @endif

                <span class="w-28 shrink-0 truncate text-body-md text-fg">{{ $region['label'] }}</span>

                <span class="w-10 shrink-0 font-mono text-label-sm text-fg-muted">{{ $sample['colo'] ?? '—' }}</span>

                <span class="hidden h-1 flex-1 overflow-hidden rounded-full bg-surface-container-high sm:block">
                    @if ($sample !== null)
                        <span
                            data-region-bar
                            class="block h-full rounded-full"
                            style="width: {{ min(100, (int) round($sample['ms'] / $latencyScaleMs * 100)) }}%"
                        ></span>
                    @endif
                </span>

                @if ($sample !== null)
                    <span data-region-ms class="w-16 shrink-0 text-right font-mono text-label-sm tabular-nums">{{ $sample['ms'] }} ms</span>
                @else
                    <span class="w-16 shrink-0 text-right font-mono text-label-sm text-fg-muted tabular-nums">{{ __('no data') }}</span>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="border-t border-border-subtle p-5">
        <div class="flex items-baseline justify-between">
            <span class="text-label-sm text-fg-muted">{{ __('Last 90 days') }}</span>
            <span class="font-mono text-label-sm text-fg-muted">{{ __('1 degraded · 1 no data') }}</span>
        </div>

        <div data-days class="mt-2.5 flex h-6 items-stretch gap-px">
            @foreach ($historyDays as $day)
                {{-- `data-state` drives the colour from CSS rather than a Tailwind
                     class, because the last segment's state is changed from JS and
                     Tailwind only generates the classes it can SEE in the source. --}}
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
