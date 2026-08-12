{{-- Footer: the page's own public URL (never the request URL), the brand line,
     and the manual theme control.

     "Powered by Uptizm" carries no `__()` deliberately. It is a brand line
     rather than copy: it names the product the same way in every language,
     exactly as the URL above it does. A locale key there would invite a
     translation of a proper noun. The theme control's three labels DO use
     `__()`: they are ordinary copy, and the keys belong to
     `lang/{en,tr}/status.php`'s `theme` group, written by a sibling step in
     this same wave. --}}
<footer class="flex flex-col items-center gap-2 py-6 text-center text-xs text-fg-muted">
    <a href="{{ route('status.show', $vm->page['slug']) }}" class="hover:underline">
        {{ route('status.show', $vm->page['slug']) }}
    </a>
    <span>Powered by Uptizm</span>

    {{--
        System / Light / Dark. No Alpine: this layout loads none, so the three
        buttons wire themselves through the small script below instead of
        `x-data`, reading and writing through `window.__uptizmTheme` (defined in
        layout.blade.php's pre-paint script) rather than a second copy of the
        storage key and version.
    --}}
    <div
        data-theme-control
        role="group"
        aria-label="{{ __('status.theme.label') }}"
        class="mt-1 inline-flex overflow-hidden rounded-md border border-border"
    >
        @foreach (['system', 'light', 'dark'] as $themeChoice)
            <button
                type="button"
                data-theme-option="{{ $themeChoice }}"
                aria-pressed="false"
                class="min-w-16 px-3 py-1 text-fg-muted transition-colors hover:bg-surface-container-high"
            >{{ __('status.theme.'.$themeChoice) }}</button>
        @endforeach
    </div>

    <script>
        (function () {
            var control = document.querySelector('[data-theme-control]');

            if (! control || ! window.__uptizmTheme) {
                return;
            }

            var buttons = control.querySelectorAll('[data-theme-option]');

            // "System" is stored as no record at all (see __uptizmTheme.write),
            // so the button that reads it back is the one whose option matches
            // the stored choice, defaulting to 'system' when there is none.
            function markActive(choice) {
                var active = choice || 'system';

                buttons.forEach(function (button) {
                    var isActive = button.getAttribute('data-theme-option') === active;

                    button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    button.classList.toggle('bg-surface-container-high', isActive);
                    button.classList.toggle('text-fg', isActive);
                });
            }

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var choice = button.getAttribute('data-theme-option');
                    var stored = choice === 'system' ? null : choice;

                    window.__uptizmTheme.write(stored);
                    window.__uptizmTheme.apply(stored);
                    markActive(stored);
                });
            });

            markActive(window.__uptizmTheme.read());
        })();
    </script>
</footer>
