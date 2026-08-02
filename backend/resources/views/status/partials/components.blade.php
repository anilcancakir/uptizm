{{--
    Component rows: label, status dot, a 90-day uptime bar (one cell per day,
    colored on the severity ladder), and the rolled-up uptime percent.
--}}
@php
    /*
     * One map for the row dot AND for every day in its strip, and it is the same
     * one the banner and the browser tab read
     * (App\Support\StatusPages\StatusPresentation). This partial used to carry a
     * second copy of the ladder, which is how a row and the banner above it end up
     * disagreeing about the same outage.
     *
     * What that map decides, and why it matters here: an unrecognised status
     * resolves to the NEUTRAL family, never to `up`. A day cell painted green is a
     * claim that the checks ran and passed, so a status nobody can vouch for must
     * not borrow it.
     */
    $statusClass = \App\Support\StatusPages\StatusPresentation::dotClass(...);
@endphp

<section class="mb-6 rounded-lg border border-border bg-surface-container">
    <h2 class="border-b border-border px-5 py-3 text-sm font-semibold text-fg-muted uppercase">Components</h2>

    @if ($vm->components === [])
        <p class="px-5 py-6 text-sm text-fg-muted">No components are currently published on this page.</p>
    @else
        <ul>
            @foreach ($vm->components as $component)
                <li class="flex flex-col gap-2 border-b border-border-subtle px-5 py-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $statusClass($component['status']) }}"></span>
                        <span class="font-medium">{{ $component['label'] }}</span>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="flex gap-px" aria-hidden="true">
                            @foreach ($component['strip'] as $day)
                                <span
                                    class="h-4 w-1 rounded-sm {{ $statusClass($day['status'] ?? '') }}"
                                    title="{{ $day['date'] }}: {{ $day['status'] ?? 'not measured' }}"
                                ></span>
                            @endforeach
                        </div>

                        {{-- An em dash, not "0%" and not "100%". A monitor whose first probe
                             has not run has no uptime to report, and printing a number
                             there is a claim about days nobody measured. --}}
                        <span class="text-sm text-fg-muted tabular-nums">
                            @if ($component['uptimePercent'] === null)
                                &mdash;
                            @else
                                {{ number_format($component['uptimePercent'], 2) }}%
                            @endif
                        </span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
