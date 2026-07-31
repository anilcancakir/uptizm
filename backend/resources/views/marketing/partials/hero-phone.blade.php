{{--
    The mobile client, drawn rather than screenshotted so it stays sharp at any
    density, follows the colour scheme, and cannot drift from the token set the
    product is actually built on.

    It lives in the platforms section rather than the hero. In the hero it sat on
    top of the desktop panel and covered the very rows that carry the region
    evidence, which is the one thing that panel exists to show.
--}}
<div data-reveal class="mx-auto w-52 shrink-0" aria-hidden="true">
    <div class="rounded-[2rem] border border-border bg-surface-container p-2">
        <div class="overflow-hidden rounded-[1.55rem] bg-surface">
            <div class="flex items-center justify-center px-4 pb-1.5 pt-3">
                <span class="h-1 w-12 rounded-full bg-fg-disabled"></span>
            </div>

            <div class="px-3 pb-3">
                <div class="flex items-baseline justify-between px-1 pb-2">
                    <span class="text-label-md text-fg">{{ __('Monitors') }}</span>
                    <span class="font-mono text-label-sm text-fg-muted">3</span>
                </div>

                <div class="space-y-1.5">
                    {{-- Dot classes are written out, never interpolated. Tailwind
                         generates from the literal strings it can see, so a
                         composed class name would compile to markup referencing a
                         class the stylesheet does not contain. --}}
                    @foreach ([
                        ['name' => 'api.acme.com', 'dot' => 'bg-up', 'ms' => '36'],
                        ['name' => 'acme.com', 'dot' => 'bg-up', 'ms' => '52'],
                        ['name' => 'checkout', 'dot' => 'bg-degraded', 'ms' => '910'],
                    ] as $row)
                        <div class="flex items-center gap-2 rounded-base bg-surface-container px-2 py-2">
                            <span class="size-1.5 shrink-0 rounded-full {{ $row['dot'] }}"></span>
                            <span class="min-w-0 flex-1 truncate font-mono text-micro text-fg">{{ $row['name'] }}</span>
                            <span class="font-mono text-micro tabular-nums text-fg-muted">{{ $row['ms'] }}ms</span>
                        </div>
                    @endforeach
                </div>

                {{-- An acknowledge button, because taking the incident is the one
                     thing a phone is genuinely better at than a laptop. --}}
                <div class="mt-3 rounded-base bg-primary px-2 py-2 text-center text-micro text-on-primary">
                    {{ __('Acknowledge') }}
                </div>
            </div>

            <div class="flex items-center justify-around border-t border-border-subtle px-3 py-2.5">
                <span class="size-1.5 rounded-full bg-primary"></span>
                <span class="size-1.5 rounded-full bg-fg-disabled"></span>
                <span class="size-1.5 rounded-full bg-fg-disabled"></span>
                <span class="size-1.5 rounded-full bg-fg-disabled"></span>
            </div>
        </div>
    </div>
</div>
