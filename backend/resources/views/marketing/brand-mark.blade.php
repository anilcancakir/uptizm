{{--
    The brand mark: a live status dot inside a ring. Same geometry as
    assets/brand/uptizm-mark.svg, which is the canonical artwork.

    Inlined rather than linked, and drawn in `currentColor` rather than the file's
    hardcoded #008560, because this page follows the visitor's system colour scheme
    and that file carries only the light-mode green. The asset's own header sanctions
    exactly this: anything needing the dark pair renders the geometry with a different
    fill instead of editing the file.

    The drop shadow is dropped on purpose. It needs an SVG filter, and at 28px it is
    invisible anyway.
--}}
<svg viewBox="0 0 64 64" class="size-7 shrink-0 text-primary" fill="none" aria-hidden="true">
    <circle cx="32" cy="32" r="25" stroke="currentColor" stroke-opacity="0.22" stroke-width="5" />
    <circle cx="32" cy="32" r="13" fill="currentColor" />
</svg>
