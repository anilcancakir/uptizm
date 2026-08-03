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
@extends('marketing.layout')

@section('title', $title.' | '.config('app.name'))

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:py-16">
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

        <h1 class="text-section text-fg">{{ $title }}</h1>

        <p class="mt-3 text-body-md text-fg-muted">
            {{ __('Every name below is a trademark of its owner. Uptizm is independent of all of them: we measure one public endpoint per service from our own regions, and quote each provider\'s published status where we quote it at all.') }}
        </p>

        @if ($services === [])
            {{-- Nothing published yet is a real state on a fresh install: the
                 catalog seeder creates every service UNPUBLISHED with its terms
                 unreviewed, because publishing is a human decision. Said plainly
                 rather than rendered as an empty list somebody reads as a bug. --}}
            <p class="mt-10 rounded-lg border border-border bg-surface-container p-5 text-body-lg text-fg-muted">
                {{ __('No service pages are published yet.') }}
            </p>
        @endif

        @foreach ($services as $entry)
            <section class="mt-6 rounded-lg border border-border bg-surface-container p-5">
                <h2 class="text-title-lg text-fg">
                    <a href="{{ $entry['path'] }}" class="underline underline-offset-2 hover:text-primary">
                        {{ $entry['service']['name'] }}
                    </a>
                </h2>

                @foreach ($entry['own']['endpoints'] as $endpoint)
                    @include('marketing.partials.provenance-row', [
                        'provenance' => $entry['own']['provenance'],
                        'provider' => $entry['service']['name'],
                        'status' => $endpoint['status'],
                        'headline' => $endpoint['stale']
                            ? __('We have no recent reading for :endpoint.', ['endpoint' => $endpoint['label']])
                            : ($endpoint['reportsProblem']
                                ? __('We could not reach :endpoint.', ['endpoint' => $endpoint['label']])
                                : __('We reached :endpoint normally.', ['endpoint' => $endpoint['label']])),
                        'detail' => $endpoint['stale']
                            ? __('Nothing has been measured in the last :count seconds, so we do not know.', ['count' => $staleAfterSeconds])
                            : ($endpoint['responseMs'] === null
                                ? __('Answered from :count regions.', ['count' => $endpoint['regionCount']])
                                : __(':ms ms on average, across the :count regions that answered.', ['ms' => $endpoint['responseMs'], 'count' => $endpoint['regionCount']])),
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
                        'detail' => null,
                        'timestamp' => $entry['feed']['fetchedAt'],
                        'ageSeconds' => $entry['feed']['ageSeconds'],
                    ])
                @endif

                @if ($entry['divergence'])
                    <p class="pt-3 text-body-md text-fg-muted">
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
    </div>
@endsection
