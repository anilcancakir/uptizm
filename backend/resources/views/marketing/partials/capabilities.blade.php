{{--
    The capability grid. Scope rule for this file: a card may only describe
    something that runs end to end today. Notably absent, and deliberately so:
    response-body assertions and structured HTTP auth (accepted by the API but
    not yet applied by the probe engine), ping and keyword checks, custom status
    page domains, white-label, and SSO.
--}}
<section id="capabilities" class="border-b border-border">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
        <div class="max-w-2xl">
            <h2 data-reveal class="text-section text-balance text-fg">{{ __('What') }} <span class="text-primary">{{ __('you get') }}</span></h2>
            <p class="mt-4 text-body-lg text-fg-muted">
                {{ __('Enough to run an on-call rotation and keep customers informed, without a second tool for the status page.') }}
            </p>
        </div>

        <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                [
                    'title' => __('HTTP and TCP checks'),
                    'body' => __('Pick the expected status code, send your own request headers and body, set the interval and the timeout per monitor. TCP monitors measure the connect and handshake.'),
                    'icon' => 'M3.75 6A2.25 2.25 0 016 3.75h12A2.25 2.25 0 0120.25 6v3A2.25 2.25 0 0118 11.25H6A2.25 2.25 0 013.75 9V6zm0 9A2.25 2.25 0 016 12.75h12A2.25 2.25 0 0120.25 15v3A2.25 2.25 0 0118 20.25H6A2.25 2.25 0 013.75 18v-3z',
                ],
                [
                    'title' => __('Metrics out of the response'),
                    'body' => __('Pull a number out of the body with JSONPath, XPath or a regex, or out of a response header, and give it warn and critical bounds. Breaching one opens an incident like any other failure.'),
                    'icon' => 'M3.75 20.25h16.5M6.75 17.25V9.75M11.25 17.25V4.5M15.75 17.25v-6M20.25 17.25v-9',
                ],
                [
                    'title' => __('Incidents with a timeline'),
                    'body' => __('Acknowledge, assign, post updates, reopen, and write the postmortem in the same place. Severity and customer impact are recorded separately, because they are not the same question.'),
                    'icon' => 'M12 6.75v3.75m0 3h.008M12 3.75l8.25 14.25H3.75L12 3.75z',
                ],
                [
                    'title' => __('On-call and escalation'),
                    'body' => __('Rotating schedules with overrides for the week someone is away, and escalation policies that walk ordered steps to a schedule or a named person until somebody acknowledges.'),
                    'icon' => 'M12 6v6l3.75 2.25M12 3.75a8.25 8.25 0 100 16.5 8.25 8.25 0 000-16.5z',
                ],
                [
                    'title' => $mailDeliverable ? __('Status pages people can subscribe to') : __('Status pages you control'),
                    'body' => $mailDeliverable
                        ? __('Publish only the components you choose, on a path or on a subdomain. Customers confirm their email before they get anything, and unsubscribe in one click. Private pages are gated by a token.')
                        : __('Publish only the components you choose, on a path or on a subdomain, each with its own public label. Private pages are gated by a token.'),
                    'icon' => 'M3.75 5.25h16.5v13.5H3.75V5.25zm0 4.5h16.5M8.25 14.25h7.5',
                ],
                [
                    'title' => __('TLS expiry, watched daily'),
                    'body' => __('Every HTTPS monitor has its certificate checked once a day and opens a warning incident inside the window you set, so a renewal you forgot does not become an outage.'),
                    'icon' => 'M12 3.75l7.5 3v5.25c0 4.14-3.09 7.5-7.5 8.25-4.41-.75-7.5-4.11-7.5-8.25V6.75l7.5-3z',
                ],
            ] as $capability)
                <article data-reveal class="rounded-lg border border-border bg-surface-container p-6 transition-colors hover:border-fg-disabled">
                    <svg class="size-6 text-fg-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $capability['icon'] }}" />
                    </svg>

                    <h3 class="mt-4 text-title-lg text-fg">{{ $capability['title'] }}</h3>
                    <p class="mt-2 text-body-md text-fg-muted">{{ $capability['body'] }}</p>
                </article>
            @endforeach
        </div>

        @php
            // Read off NotificationChannelType, so a new destination appears here
            // without anyone remembering to update a sentence.
            $channelList = count($channels) > 1
                ? implode(', ', array_slice($channels, 0, -1)).' '.__('and').' '.end($channels)
                : implode('', $channels);
        @endphp

        <p class="mt-8 text-body-md text-fg-muted">
            {{ $mailDeliverable
                ? __('Alerts reach your team over :channels, and each responder can also take incidents by email or in the app.', ['channels' => $channelList])
                : __('Alerts reach your team over :channels, and each responder can also take incidents in the app.', ['channels' => $channelList]) }}
        </p>
    </div>
</section>
