{{--
    The spotlight card under the monitor panel.

    The panel above never stops, because live multi-region checking is the core
    claim. This card takes the other three in turn, so each gets room instead of
    four things competing in one frame:

      1. a metric pulled out of the response body      (nothing in this category's
                                                        heroes does this)
      2. the AI verdict, with the evidence it cited
      3. the same client on web, iOS and Android

    Scenes are stacked absolutely inside a fixed-height box, so swapping them cannot
    shift the layout. Tabs are real buttons: a visitor who does not want to wait can
    jump, and a keyboard user can reach all three. That also means the hero is
    self-describing even in a screenshot, where no rotation is happening at all.
--}}
<div
    data-spotlight
    data-enter
    style="--enter-index: 4"
    class="rounded-lg border border-border bg-surface-container"
>
    <div class="flex items-center gap-1 border-b border-border-subtle p-2" role="tablist">
        @foreach ([
            ['key' => 'metric', 'label' => __('Custom metrics')],
            ['key' => 'ai', 'label' => __('AI triage')],
            ['key' => 'devices', 'label' => __('Everywhere')],
        ] as $i => $tab)
            <button
                type="button"
                role="tab"
                data-scene-tab="{{ $tab['key'] }}"
                aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                class="flex-1 rounded-base px-3 py-2 text-label-sm text-fg-muted transition-colors hover:text-fg aria-selected:bg-surface-container-high aria-selected:text-fg"
            >{{ $tab['label'] }}</button>
        @endforeach
    </div>

    <div class="relative h-[15rem]">
        {{--
            1. A metric out of the response body.

            No <pre>. A <pre> renders newlines and indentation literally, so the
            wrapper that holds the highlighted value plus its sweep overlay pushed the
            value onto its own line and stretched the overlay into a vertical block.
            Explicit line divs cost three more elements and cannot be broken by
            whitespace.
        --}}
        <div data-scene="metric" aria-hidden="false" class="absolute inset-0 flex flex-col p-5 font-mono text-label-sm">
            <div class="flex items-baseline justify-between">
                <span class="text-fg">GET /health</span>
                <span class="tabular-nums text-fg-muted">200 &middot; 42 ms</span>
            </div>

            <div class="mt-3 leading-relaxed text-fg-muted">
                <div>{</div>
                <div class="pl-4">
                    <span class="text-fg">"queue"</span>: { <span class="text-fg">"pending"</span>:
                    <span class="relative inline-block align-middle"><span class="relative z-10 rounded-sm bg-degraded-soft px-1 text-degraded-soft-foreground">4812</span><span data-sweep class="pointer-events-none absolute inset-y-0 -left-3 z-20 w-6 opacity-0" style="background: linear-gradient(90deg, transparent, color-mix(in srgb, var(--app-degraded) 55%, transparent), transparent)"></span></span> },
                </div>
                <div class="pl-4"><span class="text-fg">"workers"</span>: 4</div>
                <div>}</div>
            </div>

            {{-- mt-auto pins this to the bottom of the fixed-height box, so a scene
                 can never push its own footer out of view. --}}
            <div class="mt-auto flex flex-wrap items-center gap-2 border-t border-border-subtle pt-3">
                <span class="rounded-base bg-surface-container-high px-2 py-1 text-fg">queue_depth</span>
                <span class="text-fg-muted">$.queue.pending</span>
                <span class="ml-auto rounded-full bg-degraded-soft px-2.5 py-1 text-degraded-soft-foreground">
                    {{ __('warn ≥ 1000 crossed') }}
                </span>
            </div>
        </div>

        {{-- 2. The AI verdict, and what it is allowed to cite. ------------------- --}}
        <div data-scene="ai" aria-hidden="true" class="absolute inset-0 flex flex-col p-5">
            <div class="flex items-center gap-2">
                <span class="size-2 rounded-full bg-ai"></span>
                <span class="text-label-md text-fg">{{ __('Suggested cause') }}</span>
                <span class="ml-auto rounded-full bg-ai-soft px-2.5 py-1 text-label-sm text-ai-soft-foreground">
                    {{ __('AI · suggestion') }}
                </span>
            </div>

            <p class="mt-3 text-body-md text-fg">
                {{ __('Queue depth has been climbing for 3 checks and crossed the warning bound. Latency follows it.') }}
            </p>

            <ul class="mt-3 space-y-1.5 text-label-sm text-fg-muted">
                @foreach ([
                    __('queue_depth: 1,180 → 2,940 → 4,812'),
                    __('EU Central and Asia-Pacific slowed in the same window'),
                ] as $evidence)
                    <li class="flex gap-2">
                        <span class="mt-1.5 size-1 shrink-0 rounded-full bg-ai"></span>
                        <span class="font-mono">{{ $evidence }}</span>
                    </li>
                @endforeach
            </ul>

            {{-- It proposes; a human decides. Shown as two real controls because that
                 IS the product's boundary, not a disclaimer bolted on afterwards. --}}
            <div class="mt-auto flex items-center gap-2 border-t border-border-subtle pt-3">
                <span class="rounded-base bg-primary px-3 py-1.5 text-label-sm text-on-primary">{{ __('Accept') }}</span>
                <span class="rounded-base border border-border px-3 py-1.5 text-label-sm text-fg-muted">{{ __('Dismiss') }}</span>
                <span class="ml-auto text-label-sm text-fg-muted">{{ __('Cites your signals only') }}</span>
            </div>
        </div>

        {{-- 3. One client, three platforms. ------------------------------------- --}}
        <div data-scene="devices" aria-hidden="true" class="absolute inset-0 p-5">
            <p class="text-body-md text-fg-muted">
                {{ __('One Flutter codebase, so the phone is not a cut-down companion with half the screens.') }}
            </p>

            <div class="mt-4 grid grid-cols-3 gap-3">
                @foreach ([
                    ['name' => __('Web'), 'live' => config('app.client_platforms.web') === 'live', 'w' => 'w-full', 'h' => 'h-14'],
                    ['name' => 'iOS', 'live' => config('app.client_platforms.ios') === 'live', 'w' => 'w-8', 'h' => 'h-14'],
                    ['name' => 'Android', 'live' => config('app.client_platforms.android') === 'live', 'w' => 'w-8', 'h' => 'h-14'],
                ] as $p)
                    <div class="flex flex-col items-center gap-2">
                        {{-- Flat outline frames, tonal fill, no shadow. --}}
                        <div class="flex {{ $p['h'] }} {{ $p['w'] }} items-center justify-center rounded-base border border-border bg-surface">
                            <span data-platform-dot class="size-1.5 rounded-full bg-up"></span>
                        </div>
                        <span class="text-label-sm text-fg">{{ $p['name'] }}</span>
                        {{-- `soon` carries a border rather than a soft fill. The
                             `paused-soft` tone is a near-neighbour of both card
                             backgrounds, so as a fill it disappeared in light AND
                             dark; an outline reads on either. --}}
                        <span class="rounded-full px-2 py-0.5 text-micro {{ $p['live'] ? 'bg-up-soft text-up-soft-foreground' : 'border border-border text-fg-muted' }}">
                            {{ $p['live'] ? __('live') : __('soon') }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
