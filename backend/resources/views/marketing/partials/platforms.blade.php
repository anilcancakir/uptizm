{{--
    One client, three platforms, stated per platform.

    Availability comes from `app.client_platforms` rather than from this template,
    because "web, iOS and Android" as a single claim would be false today: the web
    client is live, and the mobile builds come from the same Flutter source but are
    in neither store. A platform marked anything other than `live` renders as not
    yet available, and says so plainly instead of offering a button that leads
    nowhere.
--}}
<section id="platforms" class="border-b border-border bg-surface-container">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
        <div class="max-w-2xl">
            @include('marketing.partials.eyebrow', ['text' => __('One client, every platform')])

            <h2 data-reveal class="mt-4 text-section text-balance text-fg">
                {{ __('Take the on-call rotation') }}
                <span class="text-primary">{{ __('with you') }}</span>.
            </h2>

            <p data-reveal class="mt-4 text-body-lg text-fg-muted">
                {{ __('The client is one Flutter codebase, so the phone is not a cut-down companion app with half the screens. The same monitors, the same incident timeline, the same acknowledge button.') }}
            </p>
        </div>

        <div class="mt-12 grid gap-10 lg:grid-cols-[auto_1fr] lg:items-center lg:gap-14">
            @include('marketing.partials.hero-phone')

            <div class="grid gap-6 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
            @foreach ([
                [
                    'key' => 'web',
                    'name' => __('Web'),
                    'body' => __('Any browser, nothing to install. Installable to the dock or home screen if you want it there.'),
                    'icon' => 'M12 3.75a8.25 8.25 0 100 16.5 8.25 8.25 0 000-16.5zm0 0c2.9 0 5.25 3.69 5.25 8.25S14.9 20.25 12 20.25 6.75 16.56 6.75 12 9.1 3.75 12 3.75zM3.9 9h16.2M3.9 15h16.2',
                ],
                [
                    'key' => 'ios',
                    'name' => __('iOS'),
                    'body' => __('Push straight to the phone that is on call, with the incident already open when you unlock it.'),
                    'icon' => 'M8.25 3.75h7.5A1.5 1.5 0 0117.25 5.25v13.5a1.5 1.5 0 01-1.5 1.5h-7.5a1.5 1.5 0 01-1.5-1.5V5.25a1.5 1.5 0 011.5-1.5zm2.25 13.5h3',
                ],
                [
                    'key' => 'android',
                    'name' => __('Android'),
                    'body' => __('The same build from the same source, so a fix lands on both phones in the same release.'),
                    'icon' => 'M6.75 9.75h10.5v7.5a1.5 1.5 0 01-1.5 1.5h-7.5a1.5 1.5 0 01-1.5-1.5v-7.5zm2.25-4.5l-1.5-1.5m6.75 1.5l1.5-1.5M9.75 9.75a2.25 2.25 0 014.5 0',
                ],
            ] as $platform)
                @php $isLive = config("app.client_platforms.{$platform['key']}") === 'live'; @endphp

                <article data-reveal data-lift class="rounded-lg border border-border bg-surface p-6 hover:border-fg-disabled">
                    <div class="flex items-center justify-between gap-3">
                        <svg class="size-6 {{ $isLive ? 'text-primary' : 'text-fg-muted' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $platform['icon'] }}" />
                        </svg>

                        <span class="rounded-full px-2.5 py-1 text-label-sm {{ $isLive ? 'bg-up-soft text-up-soft-foreground' : 'bg-paused-soft text-paused-soft-foreground' }}">
                            {{ $isLive ? __('Available now') : __('Not in the stores yet') }}
                        </span>
                    </div>

                    <h3 class="mt-4 text-title-lg text-fg">{{ $platform['name'] }}</h3>
                    <p class="mt-2 text-body-md text-fg-muted">{{ $platform['body'] }}</p>
                </article>
            @endforeach
            </div>
        </div>
    </div>
</section>
