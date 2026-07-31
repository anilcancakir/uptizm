{{-- Act 4: it reaches whoever is on call, wherever they are. --}}
<div data-act x-show="act === 4" x-cloak class="absolute inset-0 flex flex-col p-5">
    <div class="rounded-base border border-border bg-surface p-3">
        <div class="flex items-center gap-2">
            <span class="size-2 rounded-full bg-degraded"></span>
            <span class="text-label-md text-fg">{{ __('api.acme.com degraded') }}</span>
            <span class="ml-auto font-mono text-label-sm text-fg-muted">{{ __('now') }}</span>
        </div>
        <p class="mt-1 text-body-md text-fg-muted">
            {{ __('Queue depth 4,812 over a 1,000 bound. Two regions slow.') }}
        </p>
    </div>

    <p class="mt-5 text-label-sm uppercase tracking-[0.12em] text-fg-muted">{{ __('Reached') }}</p>

    <div class="mt-3 grid grid-cols-3 gap-3">
        @foreach ([
            ['key' => 'web', 'name' => __('Web')],
            ['key' => 'ios', 'name' => 'iOS'],
            ['key' => 'android', 'name' => 'Android'],
        ] as $p)
            @php $live = config("app.client_platforms.{$p['key']}") === 'live'; @endphp
            <div
                class="flex flex-col items-center gap-2 rounded-base border p-3 transition-colors"
                :class="delivered.includes('{{ $p['key'] }}') ? 'border-primary bg-primary-container' : 'border-border'"
            >
                <span
                    class="size-1.5 rounded-full transition-colors"
                    :style="`background-color: var(--app-${delivered.includes('{{ $p['key'] }}') ? 'primary' : 'fg-disabled'})`"
                ></span>
                <span class="text-label-sm text-fg">{{ $p['name'] }}</span>
                {{-- `soon` is an outline, not a soft fill: the paused tone is a near
                     neighbour of both card backgrounds and vanished in each. --}}
                <span class="rounded-full px-2 py-0.5 text-micro {{ $live ? 'bg-up-soft text-up-soft-foreground' : 'border border-border text-fg-muted' }}">
                    {{ $live ? __('live') : __('soon') }}
                </span>
            </div>
        @endforeach
    </div>

    <p class="mt-auto text-label-sm text-fg-muted">
        {{ __('Escalation walks your policy until somebody acknowledges.') }}
    </p>
</div>
