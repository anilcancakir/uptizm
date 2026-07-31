{{--
    Section 4: the public status page.

    Framed as a double-sided value on purpose, which is the one move this category makes
    that generic SaaS pages do not: Atlassian sells Statuspage to marketing teams as an
    "Uptime Showcase" and Pingdom's line is "transparency inspires loyalty". The status
    page is a feature you buy AND a surface that sells for you, so the section is written
    to the reader's customers rather than to the reader.

    The uptime strip is an illustration, and it says so. It uses the product's own status
    vocabulary (up / degraded / no-data) rather than invented greens, including the
    neutral tone for a day with no checks: a status page that paints missing history as
    healthy is the exact dishonesty this product spent commits removing from its own
    client.
--}}
<section id="status-pages" class="border-b border-border bg-surface-container">
    <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
        <div class="grid gap-14 lg:grid-cols-2 lg:items-center lg:gap-16">
            <div data-reveal>
                <p class="text-label-md text-accent">{{ __('Status pages') }}</p>

                <h2 class="mt-3 text-section text-balance text-fg">
                    {{ __('During an outage, your quietest channel does') }}
                    <span class="text-primary">{{ __('the most talking') }}</span>.
                </h2>

                <p class="mt-5 max-w-xl text-body-lg text-fg-muted">
                    {{ __('A page your customers can reach when nothing else of yours is up. It answers the question before it reaches your inbox, and it keeps answering it after you have gone to bed.') }}
                </p>

                <ul data-reveal style="--reveal-index: 2" class="mt-9 grid gap-3 sm:grid-cols-2">
                    @foreach ($statusPageFeatures as $feature)
                        <li class="flex items-start gap-2.5 text-body-md text-fg-muted">
                            <svg viewBox="0 0 24 24" class="mt-1 size-4 shrink-0 text-primary" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                                <path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>

                <p class="mt-8 text-body-md text-fg-muted">
                    {{ __('The same page is rendered to an image on every change, so you can see what a customer sees without publishing to find out.') }}
                </p>
            </div>

            {{-- Second in the DOM, first on a wide screen.
                 The order matters twice over. On a narrow screen this renders after the
                 heading, because it arrived before it for one commit and a phone reader
                 met an unexplained status-page mockup with no idea what section they were
                 in. And on a wide screen `lg:order-first` puts it on the left, so this
                 section mirrors the one above it instead of repeating its layout. --}}
            <div data-reveal style="--reveal-index: 1" class="lg:order-first">
                <div class="overflow-hidden rounded-lg border border-border bg-surface">
                    <div class="flex items-center justify-between border-b border-border-subtle px-5 py-4">
                        <div class="flex items-center gap-2.5">
                            <span class="size-5 rounded-base" style="background-color: var(--app-primary)" aria-hidden="true"></span>
                            <span class="text-title-lg text-fg">{{ __('Acme Status') }}</span>
                        </div>
                        <span class="text-label-sm text-fg-disabled">{{ __('Example') }}</span>
                    </div>

                    <div class="flex flex-col gap-5 p-5">
                        <div class="flex items-center gap-3 rounded-base px-4 py-3" style="background-color: var(--app-up-soft)">
                            <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--app-up)"></span>
                            <span class="text-label-md" style="color: var(--app-up-soft-foreground)">{{ __('All systems operational') }}</span>
                        </div>

                        @foreach ([
                            ['name' => __('API'), 'uptime' => '99.98%', 'dip' => null],
                            ['name' => __('Website'), 'uptime' => '99.91%', 'dip' => 61],
                            ['name' => __('Checkout'), 'uptime' => '100%', 'dip' => null],
                        ] as $component)
                            <div>
                                <div class="flex items-baseline justify-between">
                                    <span class="text-body-md text-fg">{{ $component['name'] }}</span>
                                    <span class="font-mono text-label-sm text-fg-muted">{{ $component['uptime'] }}</span>
                                </div>

                                {{-- 90 bars, one per day. The first eight carry no data,
                                     which is what a page published last week honestly
                                     looks like. --}}
                                <div
                                    data-reveal="sweep"
                                    style="--reveal-index: {{ $loop->index + 2 }}"
                                    class="mt-2 flex h-7 items-stretch gap-px"
                                    aria-hidden="true"
                                >
                                    @for ($day = 0; $day < 90; $day++)
                                        <span
                                            class="min-w-0 flex-1 rounded-[1px]"
                                            style="background-color: var(--app-{{ $day < 8 ? 'paused-soft' : ($component['dip'] === $day ? 'degraded' : 'up') }})"
                                        ></span>
                                    @endfor
                                </div>
                            </div>
                        @endforeach

                        <div class="flex flex-col gap-2 rounded-base border border-border bg-surface-container-high px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <span class="text-label-sm text-fg-muted">{{ __('Subscribe to updates') }}</span>
                            <span class="font-mono text-label-sm text-fg-disabled">you@company.com</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
