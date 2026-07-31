@php
    /*
     * Illustrative telemetry for the hero panel, keyed by the region values the
     * MonitorRegion enum defines. `colo` is the Cloudflare data centre a probe
     * for that region actually came back from during live verification, so the
     * geography is real even though the latency is an example.
     *
     * A region with no entry renders as "no data" in the neutral `paused` tone.
     * That is deliberate: it is the same thing the product does with a day it has
     * no checks for, so adding a region to the enum degrades this panel into
     * honesty rather than into a fabricated row.
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
     * degraded and one with NO DATA, because that is what the real 90-day strip
     * renders when a monitor was not being checked, and a hero that shows a
     * flawless wall of green would be advertising a product that cannot express
     * doubt.
     */
    $historyDays = collect(range(1, 90))
        ->map(fn (int $day): string => match (true) {
            $day === 62 => 'degraded',
            $day === 74 => 'none',
            default => 'up',
        });
@endphp

{{-- role="img" with a label that names this as an example: the panel is
     decorative evidence, and a screen reader should get the summary rather than
     a table of invented numbers. --}}
<div
    role="img"
    aria-label="{{ __('Example: an Uptizm monitor showing one check result per probe region.') }}"
    data-enter
    style="animation-delay: 280ms"
    class="rounded-lg border border-border bg-surface-container"
>
    <div class="flex items-start justify-between gap-4 border-b border-border-subtle p-5">
        <div class="min-w-0">
            <div class="flex items-center gap-2.5">
                <span class="size-2.5 shrink-0 rounded-full bg-up"></span>
                <span class="truncate font-mono text-body-md text-fg">api.acme.com</span>
            </div>
            <p class="mt-1.5 pl-[1.25rem] text-label-sm text-fg-muted">
                {{ __('HTTP · every 30s · :count regions', ['count' => count($regions)]) }}
            </p>
        </div>

        <span class="shrink-0 rounded-full bg-up-soft px-2.5 py-1 text-label-sm text-up-soft-foreground">
            {{ __('Operational') }}
        </span>
    </div>

    <ul class="divide-y divide-border-subtle">
        @foreach ($regions as $region)
            @php $sample = $exampleTelemetry[$region['value']] ?? null; @endphp

            <li class="flex items-center gap-3 px-5 py-3">
                <span class="size-1.5 shrink-0 rounded-full {{ $sample === null ? 'bg-paused' : 'bg-up' }}"></span>

                <span class="w-28 shrink-0 truncate text-body-md text-fg">{{ $region['label'] }}</span>

                <span class="w-10 shrink-0 font-mono text-label-sm text-fg-muted">{{ $sample['colo'] ?? '—' }}</span>

                <span class="hidden h-1 flex-1 overflow-hidden rounded-full bg-surface-container-high sm:block">
                    @if ($sample !== null)
                        <span
                            class="block h-full rounded-full bg-primary/70"
                            style="width: {{ min(100, (int) round($sample['ms'] / $latencyScaleMs * 100)) }}%"
                        ></span>
                    @endif
                </span>

                <span class="w-16 shrink-0 text-right font-mono text-label-sm tabular-nums {{ $sample === null ? 'text-fg-muted' : 'text-fg' }}">
                    {{ $sample === null ? __('no data') : $sample['ms'].' ms' }}
                </span>
            </li>
        @endforeach
    </ul>

    <div class="border-t border-border-subtle p-5">
        <div class="flex items-baseline justify-between">
            <span class="text-label-sm text-fg-muted">{{ __('Last 90 days') }}</span>
            <span class="font-mono text-label-sm text-fg-muted">{{ __('1 degraded · 1 no data') }}</span>
        </div>

        <div class="mt-2.5 flex h-6 items-stretch gap-px">
            @foreach ($historyDays as $day)
                {{-- Interpolated, not @class: that directive emits a whole
                     `class="..."` attribute, so inside one it nests and the
                     browser drops every utility after it. --}}
                <span class="flex-1 rounded-[1px] {{ match ($day) {
                    'up' => 'bg-up',
                    'degraded' => 'bg-degraded',
                    'none' => 'bg-surface-container-high',
                } }}"></span>
            @endforeach
        </div>
    </div>
</div>
