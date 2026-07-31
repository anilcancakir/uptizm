{{--
    A long-form document page: Privacy, Terms, Contact, FAQ.

    One reading column, a table of contents, the effective-date line, and the rendered
    Markdown. Everything above and below it is the shared marketing shell.

    WHAT A PAGE MUST SUPPLY

    `App\Support\Marketing\ChromeData` spread into the view data, plus:

      $title     the document's name, used for the <h1> and the browser tab
      $document  `App\Support\Marketing\LegalDocument::render()`'s return value:
                 ['html' => string, 'toc' => [['level' => int, 'text' => string,
                 'slug' => string], ...]]

    `$sections` stays at ChromeData's empty default. It means "the in-page anchors the
    header and footer nav may offer", and those are the LANDING page's; a document's own
    anchors are its headings, and they belong in the table of contents beside the text
    rather than in the site chrome.

    WHY THE BODY IS STYLED WITH DESCENDANT VARIANTS

    There is no @tailwindcss/typography in this project (see package.json), so `prose`
    does not exist and adding the plugin for four pages would pull in a whole type scale
    that contradicts the one in app.css. The variants below style the rendered Markdown
    with the app's own tokens instead: no hex, no raw pixels, and nothing that can drift
    from DESIGN.md.

    The heading ids come from LegalDocument's heading_permalink config, which writes a
    BARE slug onto the heading element itself and inserts no ¶ glyph, so there is no
    permalink anchor to style here and the TOC links to `#what-we-collect` directly.
    Scroll offset under the sticky header is already handled: app.css gives every `[id]`
    inside `main` a `scroll-margin-top`, and this whole column is inside the layout's
    `<main>`.
--}}
@extends('marketing.layout')

@section('title', $title.' | '.config('app.name'))

@section('content')
    {{-- The reading column is narrower than the chrome's `max-w-6xl` on purpose: a legal
         paragraph set to the full grid width runs past the line length anybody reads
         comfortably, and these are the pages a visitor actually has to read. --}}
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:py-16">
        <h1 class="text-section text-fg">{{ $title }}</h1>

        {{-- The effective date, or the honest absence of one. `legal.effective_date` is
             null on this deployment and config/legal.php says in terms that the catalog
             does not invent one: setting LEGAL_EFFECTIVE_DATE is what turns these pages
             live with a real date. A blank line here would read as a rendering bug and a
             guessed date would be a false statement about when the terms took effect, so
             the absence is stated instead.

             The configured value is printed verbatim rather than reparsed and reformatted,
             because the config carries no format contract; the operator writes the date as
             it should appear. --}}
        <p class="mt-3 text-label-sm text-fg-muted">
            @if (filled(config('legal.effective_date')))
                {{ __('Effective from :date', ['date' => config('legal.effective_date')]) }}
            @else
                {{ __('No effective date has been published for this document yet.') }}
            @endif
        </p>

        @if ($document['toc'] !== [])
            {{-- A document this long is unreadable without a way in. Level 1 is skipped
                 because the <h1> above already carries the document's name, and anything
                 below level 3 would make the list longer than the section it points at. --}}
            <nav
                class="mt-8 rounded-lg border border-border bg-surface-container p-4 sm:p-6"
                aria-labelledby="toc-heading"
            >
                <h2 id="toc-heading" class="text-label-sm uppercase tracking-[0.12em] text-fg-muted">
                    {{ __('On this page') }}
                </h2>

                <ul class="mt-3 space-y-2">
                    @foreach ($document['toc'] as $heading)
                        @if ($heading['level'] >= 2 && $heading['level'] <= 3)
                            <li class="{{ $heading['level'] === 3 ? 'ps-4' : '' }}">
                                <a
                                    href="#{{ $heading['slug'] }}"
                                    class="text-body-md text-fg-muted transition-colors hover:text-fg"
                                >{{ $heading['text'] }}</a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </nav>
        @endif

        {{-- The rendered Markdown. `LegalDocument` runs CommonMark with unsafe links
             disabled over admin-curated files in version control, which is what makes the
             unescaped echo safe here; nothing on this path is visitor input. --}}
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
                [&_blockquote]:mt-4 [&_blockquote]:border-s-2 [&_blockquote]:border-border [&_blockquote]:ps-4
                [&_hr]:my-10 [&_hr]:border-border-subtle
                [&_table]:mt-4 [&_table]:w-full [&_table]:text-start
                [&_th]:border-b [&_th]:border-border [&_th]:py-2 [&_th]:text-start [&_th]:text-label-md [&_th]:text-fg
                [&_td]:border-t [&_td]:border-border-subtle [&_td]:py-2 [&_td]:pe-4 [&_td]:align-top [&_td]:text-body-md [&_td]:text-fg-muted
                [&_details]:mt-3 [&_details]:rounded-md [&_details]:border [&_details]:border-border [&_details]:bg-surface-container [&_details]:p-4
                [&_summary]:cursor-pointer [&_summary]:text-label-md [&_summary]:text-fg"
        >{!! $document['html'] !!}</div>
    </div>
@endsection
