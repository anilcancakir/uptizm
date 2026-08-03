{{--
    One published catalog service: what Uptizm measured, what the provider
    publishes, and nothing synthesized from the two.

    WHAT THIS PAGE MUST SUPPLY (all from ShowServiceStatusController)

      `App\Support\Marketing\ChromeData` spread into the view data, plus:

      $title       the page's name, for the <h1> and the browser tab
      $service     ['slug' => ..., 'name' => ..., 'category' => ...]
      $own         ServicePageAssembler's own-measurement block
      $feed        its provider-feed block, or null when there is none to quote
      $divergence  whether the two blocks disagree
      $generatedAt when this read model was assembled (it is cached for a minute)
      $hubPath     the catalog hub, in the language being read
      $document    `LegalDocument::render()`'s ['html' => ..., 'toc' => [...]]
      $staleAfterSeconds, $agreeingRegions
                   ServicePageAssembler's two evidence bounds, handed over as view
                   data rather than dereferenced here as fully qualified constants,
                   the way `marketing/contact.blade.php` takes its field names

    THE FOUR THINGS THIS TEMPLATE MAY NOT DO

    1.  No percentage, anywhere. Uptizm probes ONE endpoint of one product, so a
        percentage on this page would imply coverage it does not have and would
        read as the provider's uptime. The 90-day strip is rendered as day-by-day
        reachability of the NAMED endpoint with no figure beside it, and the read
        model carries no percentage to be tempted by. This is the same defect class
        as the fabricated SLO this repo already removed once.
    2.  No bare fact of the world. Every sentence is either first person ("we
        reached ...") or a quote ("they report ..."), and every one carries a
        provenance chip and a timestamp, which is what `provenance-row` exists to
        make unavoidable.
    3.  No blending. There is no combined badge, no averaged status and no
        "real" answer. When the two blocks disagree the divergence note appears
        BESIDE both of them and explains why disagreeing is expected.
    4.  No provider artwork. No logo, no `og:image`, no `<img>` at all: a court
        cleared plain-text use of somebody else's mark in the same opinion that
        refused stylised use of it. The only structured data is `WebPage`;
        `Organization` would misrepresent whose page this is.
--}}
@extends('marketing.layout')

@section('title', $title.' | '.config('app.name'))

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:py-16">
        {{-- WebPage and nothing else. Organization or SoftwareApplication here
             would claim this page speaks for the service it names, which is a named
             violation of Google's own structured-data policy, and the FAQ rich
             result no longer exists. The hex flags stop any `</script>` in a name
             or a description from breaking out of the block. --}}
        <script type="application/ld+json">
            {!! json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $title,
                'description' => $summary,
                'url' => $canonicalUrl,
                'inLanguage' => app()->getLocale(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
        </script>

        <p class="text-label-sm text-fg-muted">
            <a href="{{ $hubPath }}" class="underline underline-offset-2 hover:text-fg">{{ __('All services') }}</a>
        </p>

        <h1 class="mt-3 text-section text-fg">{{ $title }}</h1>

        {{-- The non-affiliation line, in the FIRST screen rather than only in the
             footnotes: the page carries somebody else's trademark in its title, and
             a reader has to be able to tell whose page this is before reading a
             single status. Repeated at the end for anybody who arrives at an
             anchor. --}}
        <p class="mt-3 text-body-md text-fg-muted">
            {{ __('An independent view by Uptizm. We are not affiliated with, endorsed by or sponsored by :service, and :service is a trademark of its owner.', ['service' => $service['name']]) }}
        </p>

        <section class="mt-10" aria-labelledby="own-measurement">
            <h2 id="own-measurement" class="text-title-lg text-fg">{{ __('What Uptizm measured') }}</h2>

            @if ($own['endpoints'] === [])
                {{-- Unreachable for a published service: `Service::canPublish()`
                     requires at least one attached monitor precisely so this page
                     can never be only a re-rendered provider feed. Rendered
                     honestly rather than as an empty section, because a page that
                     silently dropped its own-measurement block would be exactly
                     the thin content this catalog refuses to publish. --}}
                <p class="mt-4 text-body-lg text-fg-muted">
                    {{ __('We have no endpoint of our own attached to this service yet, so there is nothing here we measured.') }}
                </p>
            @endif

            @foreach ($own['endpoints'] as $endpoint)
                <div class="mt-4 rounded-lg border border-border bg-surface-container p-5">
                    @include('marketing.partials.provenance-row', [
                        'provenance' => $own['provenance'],
                        'provider' => $service['name'],
                        'status' => $endpoint['status'],
                        'headline' => $endpoint['stale']
                            ? __('We have no recent reading for :endpoint.', ['endpoint' => $endpoint['label']])
                            : ($endpoint['reportsProblem']
                                ? __('We could not reach :endpoint.', ['endpoint' => $endpoint['label']])
                                : __('We reached :endpoint normally.', ['endpoint' => $endpoint['label']])),
                        'detail' => $endpoint['stale']
                            ? __('Nothing has been measured in the last :count seconds, so we do not know. We do not show you the last value we happened to have.', ['count' => $staleAfterSeconds])
                            : ($endpoint['responseMs'] === null
                                ? __('Answered from :count regions.', ['count' => $endpoint['regionCount']])
                                : __(':ms ms on average, across the :count regions that answered.', ['ms' => $endpoint['responseMs'], 'count' => $endpoint['regionCount']])),
                        'timestamp' => $endpoint['checkedAt'],
                        'ageSeconds' => $endpoint['ageSeconds'],
                    ])

                    {{-- The stricter public bar, stated instead of merely applied.
                         One region having a bad minute is not something this page
                         will call an outage, and saying so is more useful than
                         hiding the dissent. --}}
                    @if (! $endpoint['reportsProblem'] && $endpoint['dissentingRegions'] > 0)
                        <p class="pt-3 text-body-md text-fg-muted">
                            {{ __('One or more regions disagreed (:count of :total). We report a problem only after :threshold consecutive failures AND at least :quorum regions agreeing, because a public page contradicting the provider needs to be right rather than fast.', [
                                'count' => $endpoint['dissentingRegions'],
                                'total' => $endpoint['regionCount'],
                                'threshold' => $endpoint['incidentThreshold'],
                                'quorum' => $agreeingRegions,
                            ]) }}
                        </p>
                    @endif

                    @if ($endpoint['readings'] !== [])
                        <ul class="mt-4 space-y-2">
                            @foreach ($endpoint['readings'] as $reading)
                                <li class="flex flex-wrap items-center justify-between gap-2 text-body-md text-fg-muted">
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="h-2 w-2 shrink-0 rounded-full {{ \App\Support\StatusPages\StatusPresentation::dotClass($reading['ladder']) }}"
                                            aria-hidden="true"
                                        ></span>
                                        {{ $reading['label'] }}
                                    </span>

                                    <span class="tabular-nums">
                                        @if ($reading['responseMs'] === null)
                                            &mdash;
                                        @else
                                            {{ $reading['responseMs'] }} ms
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($endpoint['strip'] !== null)
                        <p class="mt-5 text-label-sm text-fg-muted">
                            {{ __('Uptizm reachability of :endpoint, last :days days. One cell per day. A day we did not measure stays neutral rather than green, and no percentage is published: we probe this one endpoint, not the whole product.', [
                                'endpoint' => $endpoint['label'],
                                'days' => count($endpoint['strip']),
                            ]) }}
                        </p>

                        <div class="mt-2 flex gap-px" aria-hidden="true">
                            @foreach ($endpoint['strip'] as $day)
                                <span
                                    class="h-4 w-1 rounded-sm {{ \App\Support\StatusPages\StatusPresentation::dotClass((string) $day['status']) }}"
                                    title="{{ $day['date'] }}: {{ $day['status'] ?? __('not measured') }}"
                                ></span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </section>

        @if ($feed !== null)
            <section class="mt-10" aria-labelledby="provider-feed">
                <h2 id="provider-feed" class="text-title-lg text-fg">
                    {{ __('What :service publishes itself', ['service' => $service['name']]) }}
                </h2>

                <div class="mt-4 rounded-lg border border-border bg-surface-container p-5">
                    {{-- The dot on this row is deliberately NEUTRAL whatever they
                         published, and the word carries the meaning instead. Their
                         `none|minor|major|critical` vocabulary is not this product's
                         `up|down|degraded`, and their "minor" can mean one
                         sub-product is slow, so recolouring their word into our
                         palette would be exactly the translation this page refuses
                         to make. Their per-component statuses below DO carry their
                         real colours: that vocabulary is byte-identical to this
                         repo's own, so it is not a translation. --}}
                    @include('marketing.partials.provenance-row', [
                        'provenance' => $feed['provenance'],
                        'provider' => $service['name'],
                        'status' => null,
                        'headline' => $feed['error'] !== null
                            ? __('We could not read their status feed.')
                            : ($feed['indicator'] === null
                                ? __('They publish no overall status word.')
                                : __('They report: :indicator', ['indicator' => $feed['indicator']])),
                        'detail' => $feed['error'] !== null
                            ? $feed['error']
                            : ($feed['stale']
                                ? __('This is what they published then, not necessarily what they publish now: it is older than our :count second freshness bound.', ['count' => $staleAfterSeconds])
                                : __('Quoted from their own status feed, unchanged.')),
                        'timestamp' => $feed['fetchedAt'],
                        'ageSeconds' => $feed['ageSeconds'],
                    ])

                    @if ($feed['components'] !== [])
                        <ul class="mt-4 space-y-2">
                            @foreach ($feed['components'] as $component)
                                <li class="flex flex-wrap items-center justify-between gap-2 text-body-md text-fg-muted">
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="h-2 w-2 shrink-0 rounded-full {{ \App\Support\StatusPages\StatusPresentation::dotClass($component['ladder']) }}"
                                            aria-hidden="true"
                                        ></span>
                                        {{ $component['label'] }}
                                    </span>

                                    {{-- Their word, printed as their word. A component
                                         status they published that this repo has no case
                                         for is unknown, never operational. --}}
                                    <span>{{ $component['status'] ?? __('not published') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($feed['incidents'] !== [])
                        <ul class="mt-4 space-y-3">
                            @foreach ($feed['incidents'] as $incident)
                                <li class="text-body-md text-fg-muted">
                                    {{-- Their incident, as their claim. Nothing here
                                         opens an incident of ours on their behalf. --}}
                                    <span class="text-fg">{{ $incident['title'] }}</span>

                                    @if ($incident['impact'] !== null)
                                        <span class="text-fg-muted">&middot; {{ $incident['impact'] }}</span>
                                    @endif

                                    @if ($incident['startedAt'] !== null)
                                        <span class="text-fg-muted">&middot; {{ $incident['startedAt'] }}</span>
                                    @endif

                                    @if ($incident['url'] !== null)
                                        <a
                                            href="{{ $incident['url'] }}"
                                            rel="nofollow noopener"
                                            class="text-primary underline underline-offset-2"
                                        >{{ __('Their update') }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        @endif

        @if ($divergence)
            {{-- BOTH blocks stay above, both keep their labels, and this explains
                 the gap instead of resolving it. There is no third answer here on
                 purpose: uptizm watches one endpoint from the outside, the provider
                 can see its own internals, and both of those can be true at once. --}}
            <section class="mt-10 rounded-lg border border-border bg-surface-container-high p-5" aria-labelledby="divergence">
                <h2 id="divergence" class="text-title-lg text-fg">{{ __('These two do not agree right now') }}</h2>

                <p class="mt-3 text-body-lg text-fg-muted">
                    {{ __('Our measurement and :service own status feed are saying different things, and both are shown above with their sources. That is expected rather than a fault: we reach one endpoint from outside their network, while they can see systems we cannot. We will not average the two or pick a winner for you.', ['service' => $service['name']]) }}
                </p>
            </section>
        @endif

        {{-- The localized prose. `LegalDocument` runs CommonMark with unsafe links
             disabled over admin-curated files in version control, which is what
             makes the unescaped echo safe; nothing on this path is visitor input.
             The styling matches `content-page`'s, so the reading experience is the
             same as on every other document page. --}}
        <div
            class="mt-10
                [&_p]:mt-4 [&_p]:text-body-lg [&_p]:text-fg-muted
                [&_h2]:mt-10 [&_h2]:text-title-lg [&_h2]:text-fg
                [&_h3]:mt-6 [&_h3]:text-label-md [&_h3]:text-fg
                [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:ps-5 [&_ol]:mt-4 [&_ol]:list-decimal [&_ol]:ps-5
                [&_li]:mt-2 [&_li]:text-body-lg [&_li]:text-fg-muted
                [&_a]:text-primary [&_a]:underline [&_a]:underline-offset-2
                [&_strong]:font-semibold [&_strong]:text-fg
                [&_code]:rounded-sm [&_code]:bg-surface-container-high [&_code]:px-1 [&_code]:font-mono [&_code]:text-body-md
                [&_hr]:my-10 [&_hr]:border-border-subtle"
        >{!! $document['html'] !!}</div>

        <p class="mt-10 border-t border-border-subtle pt-4 text-label-sm text-fg-muted">
            {{ __(':service and its logo are trademarks of their owner. This page is published by Uptizm, is not affiliated with :service, and quotes their published status only where it says so.', ['service' => $service['name']]) }}
        </p>

        <p class="mt-2 text-label-sm text-fg-muted">
            {{ __('Assembled at :time. Readings on this page are cached for up to a minute.', ['time' => $generatedAt]) }}
        </p>
    </div>
@endsection
