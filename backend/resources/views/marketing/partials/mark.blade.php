{{--
    The Uptizm mark: a live status dot inside a ring, drawn in `currentColor` so
    one file serves the header, the footer and the dark scheme without a second
    asset. Canonical artwork and the reasoning behind the two renditions live in
    assets/brand/uptizm-mark.svg.

    No drop shadow here (the canonical file has one): at header size it reads as
    a smudge, and Wind-side surfaces express depth tonally rather than with
    shadows, so the mark should not be the one exception.

    @param string $class  Sizing utilities for the <svg>.
--}}
<svg
    viewBox="0 0 64 64"
    class="{{ $class ?? 'size-7' }}"
    fill="none"
    role="img"
    aria-label="Uptizm"
>
    <circle cx="32" cy="32" r="25" stroke="currentColor" stroke-opacity="0.22" stroke-width="5" />
    <circle cx="32" cy="32" r="13" fill="currentColor" />
</svg>
