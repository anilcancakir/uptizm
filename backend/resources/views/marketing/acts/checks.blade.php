{{-- Acts 2 and 3 share the results table: act 3 is the same rows going wrong, so
     rebuilding it would throw away the continuity that makes the story read. --}}
<div data-act x-show="act === 2 || act === 3" x-cloak class="absolute inset-0 flex flex-col">
    <ul class="divide-y divide-border-subtle">
        <template x-for="row in results" :key="row.label">
            <li class="flex items-center gap-3 px-5 py-2.5" :data-state="row.state">
                <span class="relative flex size-2 shrink-0">
                    <span
                        class="relative inline-flex size-2 rounded-full transition-colors"
                        :style="`background-color: var(--app-${row.state === 'pending' ? 'fg-disabled' : row.state})`"
                    ></span>
                </span>

                <span class="w-24 shrink-0 truncate text-body-md text-fg" x-text="row.label"></span>
                <span class="w-9 shrink-0 font-mono text-label-sm text-fg-muted" x-text="row.colo"></span>

                {{-- scaleX, not width: a transform does not relayout the row. --}}
                <span class="hidden h-1 flex-1 overflow-hidden rounded-full bg-surface-container-high sm:block">
                    <span
                        class="block h-full w-full origin-left rounded-full transition-transform duration-500"
                        :style="`transform: scaleX(${row.shown === null ? 0 : Math.min(1, row.shown / 110)}); background-color: var(--app-${row.state === 'pending' ? 'fg-disabled' : row.state === 'degraded' ? 'degraded' : 'primary'})`"
                    ></span>
                </span>

                <span
                    class="w-16 shrink-0 text-right font-mono text-label-sm tabular-nums transition-colors"
                    :style="`color: var(--app-${row.state === 'degraded' ? 'degraded-soft-foreground' : row.state === 'pending' ? 'fg-muted' : 'fg'})`"
                    x-text="row.shown === null ? '···' : row.shown + ' ms'"
                ></span>
            </li>
        </template>
    </ul>

    {{-- Act 3 only: the metric that explains the slowdown, then the AI reading of it. --}}
    <div x-show="act === 3" x-cloak class="mt-auto border-t border-border-subtle p-4">
        <div class="flex items-center gap-2 font-mono text-label-sm">
            <span class="text-fg-muted">$.queue.pending</span>
            <span
                class="rounded-sm px-1.5 py-0.5 tabular-nums transition-colors"
                :style="metricBreached
                    ? 'background-color: var(--app-degraded-soft); color: var(--app-degraded-soft-foreground)'
                    : 'background-color: var(--app-surface-container-high); color: var(--app-fg)'"
                x-text="metricValue.toLocaleString('en-US')"
            ></span>
            <span x-show="metricBreached" x-cloak class="ml-auto rounded-full bg-degraded-soft px-2 py-0.5 text-degraded-soft-foreground">
                {{ __('warn ≥ 1000') }}
            </span>
        </div>

        <div x-show="showAi" x-cloak class="mt-3 rounded-base border border-ai/30 bg-surface p-3">
            <div class="flex items-center gap-2">
                <span class="size-1.5 rounded-full bg-ai"></span>
                <span class="text-label-md text-fg">{{ __('Suggested cause') }}</span>
                <span class="ml-auto rounded-full bg-ai-soft px-2 py-0.5 text-label-sm text-ai-soft-foreground">
                    {{ __('AI · suggestion') }}
                </span>
            </div>
            <p class="mt-1.5 text-body-md text-fg-muted">
                {{ __('Queue depth crossed its bound three checks ago. The two slowest regions followed it.') }}
            </p>
        </div>
    </div>
</div>
