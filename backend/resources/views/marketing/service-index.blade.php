{{--
    The catalog hub: every published service, one card each.

    WHAT THIS PAGE MUST SUPPLY (all from ShowServiceIndexController)

      `App\Support\Marketing\ChromeData` spread into the view data, plus:

      $title              the page's name, for the <h1> and the browser tab
      $services           one entry per published service: ServicePageAssembler's
                          summarized read model (no strip) plus its own `path`
      $document           `LegalDocument::render()`'s ['html' => ..., 'toc' => [...]]
      $staleAfterSeconds  the freshness bound the copy quotes

    Every row renders through the SAME `provenance-row` partial and the same
    wording the service's own page uses, so a card here cannot say something the
    page it links to would contradict, and no card can show a status without saying
    where the status came from. There is no combined column and no aggregate
    "N services operational" figure: rolling eight third parties into one number
    would be exactly the blended claim this catalog refuses to publish.
--}}
@extends('marketing.service-layout')

@section('title', $title.' | '.config('app.name'))

@section('content')
        {{-- WebPage only, like every page on this surface. --}}
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

        <header class="pb-6">
            <h1 class="text-xl font-semibold text-fg">{{ $title }}</h1>

            <p class="mt-2 text-sm text-fg-muted">
            {{ __('Every name below is a trademark of its owner. Uptizm is independent of all of them: we measure one public endpoint per service from our own regions, and quote each provider\'s published status where we quote it at all.') }}
            </p>
        </header>

        @if ($services === [])
            {{-- Nothing published yet is a real state on a fresh install: the
                 catalog seeder creates every service UNPUBLISHED with its terms
                 unreviewed, because publishing is a human decision. Said plainly
                 rather than rendered as an empty list somebody reads as a bug. --}}
            <section class="mb-6 rounded-lg border border-border bg-surface-container">
                <p class="px-5 py-6 text-sm text-fg-muted">
                    {{ __('No service pages are published yet.') }}
                </p>
            </section>
        @endif

        @foreach ($services as $entry)
            <section class="mb-6 rounded-lg border border-border bg-surface-container">
                {{-- The same tile the service's own page carries, at header-bar size.
                     It matters more here than there: this is eight rows a reader
                     scans, and a mark is what makes one findable at a glance. Same
                     rules as the page header, including the fixed white foreground
                     over an operator-set colour and the monogram fallback for a
                     service this catalog ships no mark for. --}}
                <h2 class="flex items-center gap-3 border-b border-border px-5 py-3 text-sm font-semibold text-fg-muted uppercase">
                    <span
                        @class([
                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-xs font-semibold',
                            '[&>svg]:h-4 [&>svg]:w-4 [&>svg]:fill-current',
                            'text-white' => $entry['service']['brandColor'] !== null,
                            'bg-primary text-on-primary' => $entry['service']['brandColor'] === null,
                        ])
                        @if ($entry['service']['brandColor'] !== null) style="background-color: {{ $entry['service']['brandColor'] }}" @endif
                        aria-hidden="true"
                    >@if ($entry['service']['logo'] !== null){!! $entry['service']['logo'] !!}@else{{ $entry['service']['monogram'] }}@endif</span>

                    <a href="{{ $entry['path'] }}" class="underline underline-offset-2 hover:text-fg">
                        {{ $entry['service']['name'] }}
                    </a>
                </h2>

                @foreach ($entry['own']['endpoints'] as $endpoint)
                    @include('marketing.partials.provenance-row', [
                        'provenance' => $entry['own']['provenance'],
                        'provider' => $entry['service']['name'],
                        'status' => $endpoint['status'],
                        {{-- The same three verdicts the detail page uses, and for the
                             same reason. The hub used to render only two, so a row
                             here asserted "we reached it normally" while the page it
                             links to was already qualifying that claim. A summary is
                             allowed to be shorter than the page; it is not allowed to
                             be more confident than it. --}}
                        {{-- Two stale wordings, for the same reason the mixed rung has
                             three: "nothing has been measured" is false once the
                             affirmative claim has a quorum floor, because one region may
                             well have measured and simply not be enough. --}}
                        'headline' => $endpoint['stale']
                            ? ($endpoint['regionCount'] === 0
                                ? __('We have no recent reading for :endpoint.', ['endpoint' => $endpoint['label']])
                                : __('Not enough regions answered for us to speak for :endpoint.', ['endpoint' => $endpoint['label']]))
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
                            ? ($endpoint['regionCount'] === 0
                                ? __('Nothing has been measured in the last :count seconds, so we do not know.', ['count' => $staleAfterSeconds])
                                : __('Only :count region answered our last check, which is not enough for us to publish a verdict.', ['count' => $endpoint['regionCount']]))
                            : ($endpoint['dissentingRegions'] > 0 && ! $endpoint['reportsProblem']
                                // ANY dissent, not just enough of it to reach the mixed
                                // rung. One down region, or every region answering
                                // `degraded`, left the hub printing "reached normally"
                                // while the page it links to printed the dissent
                                // paragraph. A summary may be shorter than the page; it
                                // may not be more confident.
                                ? __(':count of :total regions did not report it healthy. We do not call that an outage yet.', ['count' => $endpoint['dissentingRegions'], 'total' => $endpoint['regionCount']])
                                : ($endpoint['responseMs'] === null
                                    ? __('Answered from :count regions.', ['count' => $endpoint['regionCount']])
                                    : __(':ms ms on average, across the :count regions that answered.', ['ms' => $endpoint['responseMs'], 'count' => $endpoint['regionCount']]))),
                        'timestamp' => $endpoint['checkedAt'],
                        'ageSeconds' => $endpoint['ageSeconds'],
                    ])
                @endforeach

                @if ($entry['feed'] !== null)
                    {{-- Uncoloured on purpose, exactly as on the service's own page:
                         their overall word is their vocabulary, not this product's,
                         so the page quotes it instead of recolouring it. --}}
                    @include('marketing.partials.provenance-row', [
                        'provenance' => $entry['feed']['provenance'],
                        'provider' => $entry['service']['name'],
                        'status' => null,
                        'headline' => $entry['feed']['error'] !== null
                            ? __('We could not read their status feed.')
                            : ($entry['feed']['indicator'] === null
                                ? __('They publish no overall status word.')
                                : __('They report: :indicator', ['indicator' => $entry['feed']['indicator']])),
                        // The staleness hedge the detail page carries. Hardcoding null
                        // here let a quote from a feed that was auto-disabled hours ago
                        // read in the present tense next to a fresh timestamp.
                        'detail' => $entry['feed']['stale']
                            ? __('This is what they published then, not necessarily what they publish now: it is older than our :count second freshness bound.', ['count' => $staleAfterSeconds])
                            : null,
                        'timestamp' => $entry['feed']['fetchedAt'],
                        'ageSeconds' => $entry['feed']['ageSeconds'],
                    ])
                @endif

                @if ($entry['divergence'])
                    <p class="border-t border-border-subtle bg-surface-container-high px-5 py-3 text-sm text-fg-muted">
                        {{ __('Our measurement and theirs do not agree right now. Both are on the page.') }}
                    </p>
                @endif
            </section>
        @endforeach

        {{-- The localized prose, styled as on every other document page. Unescaped
             because `LegalDocument` renders admin-curated Markdown from version
             control with unsafe links disabled; nothing here is visitor input. --}}
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
@endsection
