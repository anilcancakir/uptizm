{{-- Brand header: the page's logo tile (in its brand color) plus its name. --}}
@php
    // The customer's own colour, already sanitised to a hex literal by the
    // assembler. It is TENANT DATA, not a design token: it cannot flip with the
    // reader's colour scheme, and it is the one inline colour on this page.
    $brandColor = $vm->page['brand_color'] ?? null;

    // The uploaded logo, already a signed URL when there is one (the assembler
    // mints it; see StatusPageAssembler::buildPage()). With an image the tile
    // carries no colour and no initials: a brand mark is either the customer's
    // artwork or our fallback, never both stacked on each other.
    $logoUrl = $vm->page['logo_url'] ?? null;

    // Two characters, defensively. The write path caps the field, but a row
    // authored before that cap, or edited outside this app, would otherwise
    // stretch a fixed 48pt square into a broken header.
    $initials = \Illuminate\Support\Str::substr(
        $vm->page['logo_text'] ?: $vm->page['name'],
        0,
        2,
    );
@endphp

<header class="flex items-center gap-3 pb-6">
    {{--
        Two different colour problems, so two different treatments.

        With a brand colour the tile is a fixed background the product does not
        choose, so the label on it is fixed white: `text-on-primary` would flip to
        near-black in dark mode over a colour that did not move, and turn a legible
        tile into an unreadable one.

        Without one the tile is OURS, so it takes the product's own brand pair and
        flips with everything else. The previous default was a hardcoded near-black,
        which read as a hole in the page in dark mode.
    --}}
    @if ($logoUrl !== null)
        {{-- `object-contain` and not `cover`: a logo is artwork with its own
             aspect ratio, and cropping someone's brand mark to fill a square is
             worse than letting it sit inside one. --}}
        <img
            src="{{ $logoUrl }}"
            alt="{{ $vm->page['name'] }}"
            width="48"
            height="48"
            class="h-12 w-12 shrink-0 rounded-lg object-contain"
        >
    @else
        <div
            @class([
                'flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-lg font-semibold',
                'text-white' => $brandColor !== null,
                'bg-primary text-on-primary' => $brandColor === null,
            ])
            @if ($brandColor !== null) style="background-color: {{ $brandColor }}" @endif
        >
            {{ $initials }}
        </div>
    @endif

    <div>
        <h1 class="text-xl font-semibold">{{ $vm->page['name'] }}</h1>
        @if ($vm->page['description'])
            <p class="text-sm text-fg-muted">{{ $vm->page['description'] }}</p>

            {{-- `sourceLocale` is null here, and it is the ONE call site where it
                 is. The other five fields are team-scoped rows with no language
                 column, so their source is `app.default_locale` by rule; this one
                 is `status_pages.locale`, which the read model does not carry.
                 The footnote drops the language name rather than guessing it. --}}
            @include('status.partials.translation-note', [
                'provenance' => $vm->page['description_provenance'],
                'original' => $vm->page['description_original'],
                'sourceLocale' => null,
            ])
        @endif
    </div>
</header>
