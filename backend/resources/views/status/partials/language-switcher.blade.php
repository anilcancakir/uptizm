{{--
    The language switcher: one real link per language this page is published in.

    It REIMPLEMENTS the markup contract of `marketing/language-menu.blade.php` on
    a different substrate, and the duplication is deliberate. What has to hold is
    the CONTRACT: every language is a real `<a href>` INCLUDING the one already
    showing (that is what keeps the switcher usable with no JavaScript, lets a
    crawler discover the other languages from the markup, and makes the self-link
    agree with the hreflang alternate pointing at the same URL), each carries
    `hreflang` and `lang`, and the current one is marked `aria-current` rather
    than disabled, because a disabled control is a dead end for a screen reader.

    What must NOT come along is the Alpine disclosure the marketing header wraps
    that contract in: this layout loads no JavaScript bundle at all, so a menu
    that needs one to open would hide every language behind a button that does
    nothing. Hence a flat row here and a menu there.

    Extracting a shared partial would be wrong too: two callers, not three, and
    they need different wrappers. Do not "fix" this duplication.

    `path` rather than `url`: the same page answers on up to three hostnames and
    a visitor should stay on the one they arrived at, while the absolute form is
    what the head's alternates and the sitemap carry.
--}}
<nav aria-label="{{ __('status.language.switcher') }}" class="mb-4 flex flex-wrap justify-end gap-x-4 gap-y-1 text-xs">
    @foreach ($localeLinks as $link)
        <a
            href="{{ $link['path'] }}"
            hreflang="{{ $link['code'] }}"
            lang="{{ $link['code'] }}"
            @if ($link['current']) aria-current="true" @endif
            class="text-fg-muted transition-colors hover:text-fg aria-[current=true]:font-semibold aria-[current=true]:text-fg"
        >{{ $link['label'] }}</a>
    @endforeach
</nav>
