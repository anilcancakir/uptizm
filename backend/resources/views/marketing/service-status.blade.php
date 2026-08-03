{{--
    One published catalog service: what Uptizm measured, what the provider
    publishes, and nothing synthesized from the two.

    WHY THIS LOOKS LIKE THE CUSTOMER STATUS PAGE

    It is the same kind of document, so it wears the same clothes: the container,
    the banner, the bordered section cards with an uppercase header bar, the
    component-row layout and the 90-day strip are all `resources/views/status/`'s,
    reusing `App\Support\StatusPages\StatusPresentation` for every dot so a colour
    here can never disagree with a colour there.

    What it does NOT borrow is `status/layout.blade.php`. That layout hardcodes
    `<html lang="en">` and emits no hreflang, so a page served from it cannot carry
    a second language or a canonical per locale. These pages exist to be found in
    two languages, so they are registered on the marketing surface and extend
    `marketing.layout` for the head, while the BODY follows the status page. Both
    surfaces are registered outside the `web` group and set no cookie.

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
      $staleAfterSeconds, $agreeingRegions, $mixedVerdict
                   ServicePageAssembler's bounds and its middle verdict, handed over
                   as view data rather than dereferenced here as fully qualified
                   constants, the way `marketing/contact.blade.php` takes its field
                   names

    THE FOUR THINGS THIS TEMPLATE MAY NOT DO

    1.  No percentage, anywhere. Uptizm probes ONE endpoint of one product, so a
        percentage on this page would imply coverage it does not have and would
        read as the provider's uptime. This is the one place the design deliberately
        DIVERGES from the customer status page, which does print a percent beside
        each component strip: that page measures a customer's own systems for the
        customer, and this one measures one endpoint of somebody else's. The strip
        is rendered with an em dash where that page puts a figure.
    2.  No bare fact of the world. Every sentence is either first person ("we
        reached ...") or a quote ("they report ..."), and every one carries a
        provenance chip and a timestamp, which is what `provenance-row` exists to
        make unavoidable.
    3.  No blending. There is no combined badge, no averaged status and no
        "real" answer. When the two blocks disagree the divergence note appears
        BESIDE both of them and explains why disagreeing is expected.
    4.  No provider artwork. No logo, no `og:image`, no `<img>` at all: a court
        cleared plain-text use of somebody else's mark in the same opinion that
        refused stylised use of it. This is also why the header has no logo tile
        where `status/partials/brand-header.blade.php` has one. The only structured
        data is `WebPage`; `Organization` would misrepresent whose page this is.
--}}
@extends('marketing.service-layout')

@section('title', $title.' | '.config('app.name'))

@section('content')
    @php
        /*
         * The banner's own sentence, from the roll-up verdict. Scoped to "what we
         * watch" rather than to the service, because uptizm watches one endpoint per
         * monitor and the banner must not read as a verdict on the provider's whole
         * product. Same three-way honesty as the per-endpoint rows below.
         */
        $bannerHeadline = match ($own['status']) {
            \App\Services\StatusPages\StatusPageAssembler::STATUS_UNKNOWN
                => __('We have nothing recent enough to report.'),
            \App\Services\Services\ServicePageAssembler::VERDICT_UNREACHABLE
                => __('We could not reach what we watch.'),
            $mixedVerdict
                => __('Not everything we watch is answering normally.'),
            default
                => __('Everything we watch is answering normally.'),
        };
    @endphp

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

        {{-- The status page's brand header, with the service's own mark where a
             customer's page carries theirs.

             The mark is SELF-HOSTED and inlined from `resources/svg/brands/`: loading
             it from a CDN or the provider's own host would make that party a recipient
             of every visitor's IP address and falsify what
             `resources/legal/privacy.en.md` publishes about this surface reaching no
             third-party host. Six of the eight seeded services ship one; OpenAI and
             Slack do not, because the CC0 dataset the rest came from removes a brand
             when its owner asks, and they fall back to the monogram.

             `brand_color` is TENANT-STYLE DATA and not a design token, so it is an
             inline style and the foreground over it is a FIXED white rather than
             `text-on-primary`, which would flip to near-black in dark mode over a
             colour that did not move and turn a legible tile unreadable. Exactly the
             treatment and exactly the reasoning of
             `status/partials/brand-header.blade.php`. Without a colour the tile takes
             the product's own brand pair, which is that file's own fallback too. --}}
        <header class="pb-6">
            <p class="text-label-sm text-fg-muted">
                <a href="{{ $hubPath }}" class="underline underline-offset-2 hover:text-fg">{{ __('All services') }}</a>
            </p>

            <div class="mt-3 flex items-center gap-3">
                <div
                    @class([
                        'flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-lg font-semibold',
                        '[&>svg]:h-7 [&>svg]:w-7 [&>svg]:fill-current',
                        'text-white' => $service['brandColor'] !== null,
                        'bg-primary text-on-primary' => $service['brandColor'] === null,
                    ])
                    @if ($service['brandColor'] !== null) style="background-color: {{ $service['brandColor'] }}" @endif
                    aria-hidden="true"
                >@if ($service['logo'] !== null){!! $service['logo'] !!}@else{{ $service['monogram'] }}@endif</div>

                <h1 class="text-xl font-semibold text-fg">{{ $title }}</h1>
            </div>

            {{-- The non-affiliation line, in the FIRST screen rather than only in the
                 footnotes: the page carries somebody else's trademark in its title,
                 and a reader has to be able to tell whose page this is before reading
                 a single status. Repeated at the end for anybody who arrives at an
                 anchor. --}}
            <p class="mt-2 text-sm text-fg-muted">
                {{ __('An independent view by Uptizm. We are not affiliated with, endorsed by or sponsored by :service, and :service is a trademark of its owner.', ['service' => $service['name']]) }}
            </p>
        </header>

        {{-- The overall banner, the customer status page's signature element. It
             carries the provenance chip like every other status statement here: the
             banner is a claim too, and an unlabelled one would be the page's most
             prominent unattributed fact. --}}
        <section class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface-container px-5 py-4">
            <div class="flex items-center gap-3">
                <span
                    class="h-3 w-3 shrink-0 rounded-full {{ \App\Support\StatusPages\StatusPresentation::dotClass((string) $own['status']) }}"
                    aria-hidden="true"
                ></span>
                <span class="text-lg font-semibold text-fg">{{ $bannerHeadline }}</span>
                <span class="rounded-full bg-surface-container-high px-2 py-0.5 text-label-sm text-fg-muted">
                    {{ __('Measured by Uptizm') }}
                </span>
            </div>

            {{-- The relative phrase and nothing else, the way the customer status
                 page's banner reads. The exact instant stays in `datetime`, which the
                 shell's script rewrites into the reader's own zone, so the short text
                 costs no precision. The cache caveat that used to sit here was a
                 paragraph of implementation detail in the most prominent line on the
                 page; it belongs in the footnote, and that is where it went. --}}
            <time datetime="{{ $generatedAt }}" class="text-sm text-fg-muted">
                {{ __('updated :ago', ['ago' => $generatedAtAgo]) }}
            </time>
        </section>

        <section class="mb-6 rounded-lg border border-border bg-surface-container" aria-labelledby="own-measurement">
            <h2 id="own-measurement" class="border-b border-border px-5 py-3 text-sm font-semibold text-fg-muted uppercase">
                {{ __('What Uptizm measured') }}
            </h2>

            @if ($own['endpoints'] === [])
                {{-- Unreachable for a published service: `Service::scopePubliclyVisible()`
                     requires an attached monitor on every read precisely so this page
                     can never be only a re-rendered provider feed. Rendered honestly
                     rather than as an empty section, because a page that silently
                     dropped its own-measurement block would be exactly the thin
                     content this catalog refuses to publish. --}}
                <p class="px-5 py-6 text-sm text-fg-muted">
                    {{ __('We have no endpoint of our own attached to this service yet, so there is nothing here we measured.') }}
                </p>
            @endif

            @foreach ($own['endpoints'] as $endpoint)
                @include('marketing.partials.provenance-row', [
                    'provenance' => $own['provenance'],
                    'provider' => $service['name'],
                    'status' => $endpoint['status'],
                    {{-- Four verdicts and not two. The middle rung exists because the
                         page used to claim "we reached it normally" while its fresh
                         regions were reporting down: the streak that gates
                         `reportsProblem` resets on any non-down result, so a partial
                         outage never satisfies it. Withholding the claim is the honest
                         answer, not asserting the opposite one. Its three wordings
                         exist because one sentence was false in two of the three
                         states, and `downRegions === 0` is tested FIRST because
                         all-degraded satisfies both conditions. --}}
                    'headline' => $endpoint['stale']
                        ? __('We have no recent reading for :endpoint.', ['endpoint' => $endpoint['label']])
                        : ($endpoint['reportsProblem']
                            ? __('We could not reach :endpoint.', ['endpoint' => $endpoint['label']])
                            : ($endpoint['status'] === $mixedVerdict
                                ? ($endpoint['downRegions'] === 0
                                    ? __('Every region reached :endpoint, but not all of them normally.', ['endpoint' => $endpoint['label']])
                                    : ($endpoint['upRegions'] === 0
                                        ? __('No region reached :endpoint on our last check, and we do not call that an outage yet.', ['endpoint' => $endpoint['label']])
                                        : __('We are reaching :endpoint from some regions and not others.', ['endpoint' => $endpoint['label']])))
                                : __('We reached :endpoint normally.', ['endpoint' => $endpoint['label']]))),
                    'detail' => $endpoint['stale']
                        ? __('Nothing has been measured in the last :count seconds, so we do not know. We do not show you the last value we happened to have.', ['count' => $staleAfterSeconds])
                        : ($endpoint['responseMs'] === null
                            ? __('Answered from :count regions.', ['count' => $endpoint['regionCount']])
                            : __(':ms ms on average, across the :count regions that answered.', ['ms' => $endpoint['responseMs'], 'count' => $endpoint['regionCount']])),
                    'timestamp' => $endpoint['checkedAt'],
                    'ageSeconds' => $endpoint['ageSeconds'],
                ])

                <div class="border-b border-border-subtle px-5 pb-4 last:border-b-0">
                    {{-- The stricter public bar, stated instead of merely applied.
                         One region having a bad minute is not something this page
                         will call an outage, and saying so is more useful than
                         hiding the dissent. --}}
                    @if (! $endpoint['reportsProblem'] && $endpoint['dissentingRegions'] > 0)
                        <p class="text-sm text-fg-muted">
                            {{ __('One or more regions disagreed (:count of :total). We report a problem only after :threshold consecutive failures AND at least :quorum regions agreeing, because a public page contradicting the provider needs to be right rather than fast.', [
                                'count' => $endpoint['dissentingRegions'],
                                'total' => $endpoint['regionCount'],
                                'threshold' => $endpoint['incidentThreshold'],
                                'quorum' => $agreeingRegions,
                            ]) }}
                        </p>
                    @endif

                    {{-- Per-region readings, in the component-row layout: dot and
                         label left, figure right, tabular so the numbers line up. --}}
                    @if ($endpoint['readings'] !== [])
                        <ul class="mt-3">
                            @foreach ($endpoint['readings'] as $reading)
                                <li class="flex items-center justify-between gap-3 py-1.5 text-sm text-fg-muted">
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

                    {{-- The 90-day strip, laid out exactly as the customer status
                         page's component strip, with ONE deliberate difference: where
                         that page prints a percentage beside it, this prints an em
                         dash. See rule 1 in the file header. --}}
                    @if ($endpoint['strip'] !== null)
                        <div class="mt-4 flex items-center gap-3">
                            <div class="flex gap-px" aria-hidden="true">
                                @foreach ($endpoint['strip'] as $day)
                                    <span
                                        class="h-4 w-1 rounded-sm {{ \App\Support\StatusPages\StatusPresentation::dotClass((string) $day['status']) }}"
                                        title="{{ $day['date'] }}: {{ $day['status'] ?? __('not measured') }}"
                                    ></span>
                                @endforeach
                            </div>

                            <span class="text-sm text-fg-muted tabular-nums">&mdash;</span>
                        </div>

                        <p class="mt-2 text-label-sm text-fg-muted">
                            {{ __('Uptizm reachability of :endpoint, last :days days. One cell per day. A day we did not measure stays neutral rather than green, and no percentage is published: we probe this one endpoint, not the whole product.', [
                                'endpoint' => $endpoint['label'],
                                'days' => count($endpoint['strip']),
                            ]) }}
                        </p>
                    @endif
                </div>
            @endforeach
        </section>

        @if ($feed !== null)
            <section class="mb-6 rounded-lg border border-border bg-surface-container" aria-labelledby="provider-feed">
                <h2 id="provider-feed" class="border-b border-border px-5 py-3 text-sm font-semibold text-fg-muted uppercase">
                    {{ __('What :service publishes itself', ['service' => $service['name']]) }}
                </h2>

                {{-- The dot on this row is deliberately NEUTRAL whatever they
                     published, and the word carries the meaning instead. Their
                     `none|minor|major|critical` vocabulary is not this product's
                     `up|down|degraded`, and their "minor" can mean one sub-product is
                     slow, so recolouring their word into our palette would be exactly
                     the translation this page refuses to make. Their per-component
                     statuses below DO carry their real colours: that vocabulary is
                     byte-identical to this repo's own, so it is not a translation. --}}
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

                {{-- Their components, in the same row layout as ours above, so a
                     reader compares like with like. Their WORD on the right where our
                     rows put a latency. --}}
                @if ($feed['components'] !== [])
                    <ul class="border-b border-border-subtle px-5 py-2 last:border-b-0">
                        @foreach ($feed['components'] as $component)
                            <li class="flex items-center justify-between gap-3 py-1.5 text-sm text-fg-muted">
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

                {{-- Their open incidents, in the customer status page's incident
                     shape: title, a badge, a timestamp, and a link out. Nothing here
                     opens an incident of OURS on their behalf. --}}
                @if ($feed['incidents'] !== [])
                    <ul class="divide-y divide-border-subtle">
                        @foreach ($feed['incidents'] as $incident)
                            <li class="px-5 py-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-fg">{{ $incident['title'] }}</span>

                                    @if ($incident['impact'] !== null)
                                        <span class="rounded-full bg-surface-container-high px-2 py-0.5 text-xs font-medium text-fg-muted">
                                            {{ $incident['impact'] }}
                                        </span>
                                    @endif

                                    @if ($incident['startedAt'] !== null)
                                        <time datetime="{{ $incident['startedAt'] }}" class="text-xs text-fg-muted">
                                            {{ $incident['startedAt'] }}
                                        </time>
                                    @endif
                                </div>

                                @if ($incident['url'] !== null)
                                    <p class="mt-1">
                                        <a
                                            href="{{ $incident['url'] }}"
                                            rel="nofollow noopener"
                                            class="text-sm text-primary underline underline-offset-2"
                                        >{{ __('Their update') }}</a>
                                    </p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @if ($divergence)
            {{-- BOTH blocks stay above, both keep their labels, and this explains
                 the gap instead of resolving it. There is no third answer here on
                 purpose: uptizm watches one endpoint from the outside, the provider
                 can see its own internals, and both of those can be true at once. --}}
            <section class="mb-6 rounded-lg border border-border bg-surface-container-high" aria-labelledby="divergence">
                <h2 id="divergence" class="border-b border-border px-5 py-3 text-sm font-semibold text-fg-muted uppercase">
                    {{ __('These two do not agree right now') }}
                </h2>

                <p class="px-5 py-4 text-sm text-fg-muted">
                    {{ __('Our measurement and :service own status feed are saying different things, and both are shown above with their sources. That is expected rather than a fault: we reach one endpoint from outside their network, while they can see systems we cannot. We will not average the two or pick a winner for you.', ['service' => $service['name']]) }}
                </p>
            </section>
        @endif

        {{-- The localized prose. `LegalDocument` runs CommonMark with unsafe links
             disabled over admin-curated files in version control, which is what
             makes the unescaped echo safe; nothing on this path is visitor input. --}}
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
@endsection
