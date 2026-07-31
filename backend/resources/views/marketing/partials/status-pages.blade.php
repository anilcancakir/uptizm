{{--
    Status pages. Panel on the left this time, so the page alternates its
    two-column rhythm rather than settling into one.

    URL forms named here are the two that are actually routed: path and
    subdomain. `DomainMode` also accepts `custom`, but no route answers on a
    customer's own hostname yet, so it is not mentioned.
--}}
<section class="border-b border-border">
    <div class="mx-auto grid max-w-6xl gap-12 px-4 py-16 sm:px-6 sm:py-20 lg:grid-cols-2 lg:items-center lg:gap-16 lg:px-8">
        <div
            role="img"
            aria-label="{{ __('Example: a published Uptizm status page listing three components and their recent uptime.') }}"
            class="rounded-lg border border-border bg-surface-container p-6 lg:order-first"
        >
            <div class="flex items-center gap-2.5">
                @include('marketing.partials.mark', ['class' => 'size-5 text-primary'])
                <span class="text-title-lg text-fg">Acme Status</span>
            </div>

            <div class="mt-5 flex items-center gap-3 rounded-md bg-up-soft px-4 py-3.5">
                <span class="size-2.5 shrink-0 rounded-full bg-up"></span>
                <div class="min-w-0">
                    <p class="text-label-md text-up-soft-foreground">{{ __('All systems operational') }}</p>
                    {{-- Full-opacity token: an alpha-reduced foreground on a soft
                         background is exactly how a designed contrast pair slips
                         under 4.5:1. --}}
                    <p class="mt-0.5 text-label-sm text-up-soft-foreground">{{ __('updated 2 minutes ago') }}</p>
                </div>
            </div>

            <p class="mt-6 text-label-sm uppercase tracking-[0.14em] text-fg-muted">{{ __('Components') }}</p>

            <div class="mt-3 space-y-4">
                {{-- The percentage and the bar have to agree: Checkout is the one
                     with a degraded day, so it is also the one that is not at
                     100%. An example that contradicts itself is worse than no
                     example. --}}
                @foreach ([
                    ['name' => 'API', 'uptime' => '100.00%', 'degradedDay' => null],
                    ['name' => 'Website', 'uptime' => '99.99%', 'degradedDay' => null],
                    ['name' => 'Checkout', 'uptime' => '99.86%', 'degradedDay' => 31],
                ] as $component)
                    <div>
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-body-md text-fg">{{ $component['name'] }}</span>
                            <span class="font-mono text-label-sm text-fg-muted tabular-nums">{{ $component['uptime'] }}</span>
                        </div>
                        <div class="mt-2 flex h-2.5 items-stretch gap-px">
                            @foreach (range(1, 45) as $day)
                                <span class="flex-1 rounded-[1px] {{ $day === $component['degradedDay'] ? 'bg-degraded' : 'bg-up' }}"></span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($mailDeliverable)
                <div class="mt-6 flex flex-wrap gap-2 border-t border-border-subtle pt-5">
                    <span class="min-w-0 flex-1 rounded-base bg-surface-container-high px-3 py-2.5 text-body-md text-fg-muted">
                        {{ __('you@company.com') }}
                    </span>
                    <span class="rounded-base bg-primary px-4 py-2.5 text-label-md text-on-primary">
                        {{ __('Subscribe') }}
                    </span>
                </div>
            @endif
        </div>

        <div>
            <p class="text-label-sm uppercase tracking-[0.14em] text-fg-muted">{{ __('Tell them before they ask') }}</p>
            <h2 class="mt-3 text-section text-balance text-fg">{{ __('A status page that is part of the monitoring, not a second job') }}</h2>

            <p class="mt-4 text-body-lg text-fg-muted">
                {{ __('The components are your monitors, so the page moves on its own when a check fails. You decide which ones are published and what each is called in public.') }}
            </p>

            <dl class="mt-8 space-y-5">
                @foreach (array_filter([
                    [
                        'term' => __('On a path or a subdomain'),
                        'desc' => __('Serve it under your account at /s/your-team, or on its own subdomain. Reserved names are refused, so nobody claims the one that looks official.'),
                    ],
                    $mailDeliverable ? [
                        'term' => __('Subscribers who opted in'),
                        'desc' => __('Email subscribers confirm before they receive anything and unsubscribe in a single click. No confirmation, no delivery.'),
                    ] : null,
                    [
                        'term' => __('The preview is the real page'),
                        'desc' => __('While you edit, the editor renders the actual published page in a real browser and shows you that image. It is not a second implementation that can quietly disagree with what customers see.'),
                    ],
                ]) as $item)
                    <div class="border-l-2 border-border pl-5">
                        <dt class="text-title-lg text-fg">{{ $item['term'] }}</dt>
                        <dd class="mt-1.5 text-body-md text-fg-muted">{{ $item['desc'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>
</section>
