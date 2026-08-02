{{-- Brand header: the page's logo tile (in its brand color) plus its name. --}}
@php
    // The customer's own colour, already sanitised to a hex literal by the
    // assembler. It is TENANT DATA, not a design token: it cannot flip with the
    // reader's colour scheme, and it is the one inline colour on this page.
    $brandColor = $vm->page['brand_color'] ?? null;
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
    <div
        @class([
            'flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-lg font-semibold',
            'text-white' => $brandColor !== null,
            'bg-primary text-on-primary' => $brandColor === null,
        ])
        @if ($brandColor !== null) style="background-color: {{ $brandColor }}" @endif
    >
        {{ $vm->page['logo_text'] ?? \Illuminate\Support\Str::substr($vm->page['name'], 0, 2) }}
    </div>

    <div>
        <h1 class="text-xl font-semibold">{{ $vm->page['name'] }}</h1>
        @if ($vm->page['description'])
            <p class="text-sm text-fg-muted">{{ $vm->page['description'] }}</p>
        @endif
    </div>
</header>
