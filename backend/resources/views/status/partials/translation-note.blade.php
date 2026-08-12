{{--
    The provenance footnote under ONE machine-translated free-text field: a quiet
    line saying where the text above it came from which is ALSO the control that
    opens the operator's own words, in a native `<details>`, one click away.

    One line rather than two. A separate sentence plus a separate "show original"
    put two lines of furniture under every translated field, and an incident with
    four updates then spent half its vertical space annotating itself. Merging
    them keeps both promises (the reader is told it is a translation, and the
    original is one click down) at half the cost.

    `authored` renders NOTHING AT ALL. It is the majority state and the whole of
    the default-language page, so a stray line, wrapper or disclosure here would
    change every page that exists today for no reason.

    A `<details>` rather than a toggle: it is the one disclosure a browser opens
    with no JavaScript, and this layout deliberately ships none.

    Three parameters, all REQUIRED and all dereferenced unguarded, matching the
    contract the rest of this surface reads its view data under (a caller that
    forgets one throws rather than rendering a footnote with a hole in it):

      - `$provenance`   one of {@see StatusPageAssembler}'s four PROVENANCE_* values.
      - `$original`     the operator's text, as authored.
      - `$sourceLocale` the language `$original` is in, or null when the caller
                        cannot know it. Null is not a shrug: the read model
                        carries no per-field source locale, and the page
                        description's source is `status_pages.locale`, which no
                        view can see. The copy degrades to an unnamed source
                        there rather than printing a guess as a fact.
--}}
@use('App\Services\StatusPages\StatusPageAssembler')

@if ($provenance === StatusPageAssembler::PROVENANCE_TRANSLATED)
    @php
        // The source language NAMED IN THE PAGE'S LANGUAGE, so a Turkish page
        // reads "İngilizce dilinden" and not "English dilinden". The switcher
        // wants the opposite (a language names itself there), which is why this
        // does not reuse the chrome's endonym labels.
        $sourceLanguage = $sourceLocale === null
            ? null
            : \Illuminate\Support\Str::ucfirst(\Locale::getDisplayLanguage($sourceLocale, app()->getLocale()));
    @endphp

    {{-- The provenance sentence IS the disclosure's label, so one line carries
         both halves of what the reader is owed: that this text was translated,
         and the way to the operator's own words. As two separate lines it cost
         two lines under every field, which on an incident with four updates was
         half the vertical space of the timeline it annotates. The sentence stays
         visible with the disclosure closed and with no JavaScript, because
         `<summary>` content always renders. --}}
    <details class="mt-1 text-xs">
        <summary class="cursor-pointer text-fg-muted hover:text-fg">
            @if ($sourceLanguage === null)
                {{ __('status.translation.translated') }}
            @else
                {{ __('status.translation.translated_from', ['language' => $sourceLanguage]) }}
            @endif
            {{ __('status.translation.show_original') }}
        </summary>

        {{-- `whitespace-pre-line` on every original, not just the postmortem's:
             the shown value renders the operator's line breaks at three of the
             six call sites, and an original that collapsed them would read as a
             different text than the one it is offered as. --}}
        <p
            @if ($sourceLocale !== null) lang="{{ $sourceLocale }}" @endif
            class="mt-1 text-sm whitespace-pre-line text-fg-muted"
        >{{ $original }}</p>
    </details>
@elseif ($provenance === StatusPageAssembler::PROVENANCE_PENDING)
    <p class="mt-1 text-xs text-fg-muted">{{ __('status.translation.pending') }}</p>
@elseif ($provenance === StatusPageAssembler::PROVENANCE_UNAVAILABLE)
    <p class="mt-1 text-xs text-fg-muted">{{ __('status.translation.unavailable') }}</p>
@endif
