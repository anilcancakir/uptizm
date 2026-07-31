{{-- Act 1: point it at a URL and pick where to check from. --}}
<div data-act x-show="Math.floor(act) === 1 && act !== 1.5" x-cloak class="absolute inset-0 flex flex-col p-5">
    <p class="text-label-sm uppercase tracking-[0.12em] text-fg-muted">{{ __('Endpoint') }}</p>

    <div class="mt-2 flex items-center gap-2 rounded-base border border-border bg-surface px-3 py-2.5">
        <span class="font-mono text-label-sm text-fg-muted">GET</span>
        {{-- The caret only exists while the text is still arriving. --}}
        <span
            class="min-w-0 flex-1 truncate font-mono text-body-md text-fg"
            :data-caret="url.length < 27 ? '' : null"
            x-text="url"
        ></span>
    </div>

    <p class="mt-5 text-label-sm uppercase tracking-[0.12em] text-fg-muted">
        {{ __('Check from') }}
        <span class="text-fg" x-text="pickedRegions ? `(${pickedRegions})` : ''"></span>
    </p>

    <div class="mt-2 flex flex-wrap gap-2">
        @foreach ($regions as $i => $region)
            <span
                class="rounded-full border px-3 py-1 text-label-sm transition-colors"
                :class="pickedRegions > {{ $i }}
                    ? 'border-primary bg-primary-container text-accent'
                    : 'border-border text-fg-muted'"
            >{{ $region['label'] }}</span>
        @endforeach
    </div>

    {{-- Real monitor fields, and they earn their place twice: they fill what was a
         dead 200px gap between the chips and the button, and they are the settings a
         visitor wants to know exist before signing up. --}}
    <div class="mt-5 grid grid-cols-3 gap-2">
        @foreach ([
            ['label' => __('Interval'), 'value' => '30s'],
            ['label' => __('Timeout'), 'value' => '10s'],
            ['label' => __('Expect'), 'value' => '200'],
        ] as $field)
            <div class="rounded-base border border-border bg-surface px-3 py-2">
                <p class="text-micro uppercase tracking-[0.12em] text-fg-muted">{{ $field['label'] }}</p>
                <p class="mt-0.5 font-mono text-body-md text-fg">{{ $field['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-auto flex items-center gap-3">
        <span
            class="rounded-md px-4 py-2 text-label-md transition-transform"
            :class="submitting ? 'scale-95 bg-accent text-on-primary' : 'bg-primary text-on-primary'"
        >{{ __('Create monitor') }}</span>
        <span class="text-label-sm text-fg-muted">{{ __('No agent to install') }}</span>
    </div>
</div>
