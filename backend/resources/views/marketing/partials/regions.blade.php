{{--
    The regions, enumerated from the MonitorRegion enum the write requests
    validate against. Nothing here is written by hand, so the page cannot
    advertise a geography a monitor cannot actually be pinned to.
--}}
<section id="regions" class="border-b border-border">
    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 sm:py-20 lg:grid-cols-[1fr_1.15fr] lg:gap-16 lg:px-8">
        <div>
            <h2 class="text-section text-balance text-fg">{{ __('Checked from where it matters') }}</h2>
            <p class="mt-4 text-body-lg text-fg-muted">
                {{ __('Probes run in workers pinned to a geography, not from one origin with a region printed next to the result. Each check records the data centre it actually left from, so the claim is auditable after the fact.') }}
            </p>
            <p class="mt-4 text-body-md text-fg-muted">
                {{ __('Pick the regions per monitor. An endpoint that only serves Europe does not need to be woken up from Singapore.') }}
            </p>
        </div>

        {{-- A divided list rather than a grid: the region count is whatever the
             enum says, and an odd count in a two-column grid leaves a visible
             empty cell. This cannot develop a hole. --}}
        <ul class="divide-y divide-border-subtle self-start overflow-hidden rounded-lg border border-border bg-surface-container">
            @foreach ($regions as $region)
                {{-- No status dot on these rows. This is the catalogue of regions
                     you can select, not a report on their health, and a green dot
                     would claim a state nothing here measures. Status colour stays
                     reserved for actual status. --}}
                <li class="flex items-center justify-between gap-4 px-5 py-4">
                    <span class="text-body-md text-fg">{{ $region['label'] }}</span>
                    <span class="font-mono text-label-sm text-fg-muted">{{ $region['value'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</section>
