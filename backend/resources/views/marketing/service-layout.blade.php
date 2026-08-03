<!doctype html>
{{--
    Standalone shell for the public service catalog: the hub at `/status` and one
    page per published service.

    WHY THIS EXISTS INSTEAD OF `marketing/layout.blade.php`

    These are STATUS DOCUMENTS. A reader arrives because something they depend on
    might be broken, and the marketing chrome answers a different question: a sticky
    nav, a language dropdown, "Sign in", "Start free", the full product footer and a
    consent banner. Rendered through that layout the pages read as marketing pages
    that happen to contain a status, which is what they looked like before this shell
    existed. `resources/views/status/layout.blade.php` is the shape a status document
    takes here, and this mirrors it: its body classes, its container, its
    time-localisation script, and nothing above the content but the page's own header.

    WHY NOT `status/layout.blade.php` ITSELF

    That layout hardcodes `<html lang="en">`, emits no hreflang and takes its title
    and canonical from a customer's `StatusPageViewModel`. A page served from it
    cannot carry a second language or a canonical per locale, and being found in two
    languages is the entire reason this catalog exists. So the HEAD is the marketing
    surface's (through `partials/seo-head`, shared with that layout so the two cannot
    drift) and the BODY is the status page's.

    WHAT IT DELIBERATELY DOES NOT CARRY

    No analytics and no consent banner, matching the customer status page rather than
    the marketing one. These pages set no cookie at all and reach no third-party host,
    which is what `resources/legal/privacy.en.md` publishes about the read-only public
    surface; adding a tag container here would make that a per-page question.

    It DOES carry a language switcher, because it has to: the pages are published in
    every supported locale and a reader on the Turkish page needs the English one
    without going via the home page. It is two links rather than the marketing
    dropdown, built from ChromeData's own locale set, so it lands on the SAME service
    page in the other language.

    WHAT AN INCLUDING PAGE MUST SUPPLY

    `App\Support\Marketing\ChromeData` spread into the view data ($summary,
    $canonicalUrl, $localeLinks, $defaultLocaleUrl), plus a `title` section.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('marketing.partials.seo-head')

        {{-- The document's colours already follow the reader through custom
             properties that flip on `prefers-color-scheme`. This tells the browser to
             bring its own furniture along (scrollbar, focus ring, autofill), which
             otherwise stays light on a dark page. Same declaration and same reason as
             the customer status page's shell. --}}
        <meta name="color-scheme" content="light dark">

        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-surface text-fg antialiased">
        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            @yield('content')

            {{-- The footer a status document gets: where to read this in another
                 language, and who published it. No product navigation and no call to
                 action, because neither is what the reader came for. --}}
            <footer class="mt-10 flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle pt-4 text-label-sm text-fg-muted">
                <p class="flex flex-wrap items-center gap-3">
                    @foreach ($localeLinks as $link)
                        @if ($link['current'])
                            <span aria-current="true" class="text-fg">{{ $link['label'] }}</span>
                        @else
                            {{-- The same service page in the other language, not the
                                 home page: ChromeData composes these from THIS page's
                                 own path. --}}
                            <a
                                href="{{ $link['path'] }}"
                                hreflang="{{ $link['code'] }}"
                                class="underline underline-offset-2 hover:text-fg"
                            >{{ $link['label'] }}</a>
                        @endif
                    @endforeach
                </p>

                <p>
                    <a href="{{ $defaultLocaleUrl }}" class="sr-only">{{ $canonicalUrl }}</a>
                    {{ __('Published by Uptizm') }}
                </p>
            </footer>
        </div>

        {{-- Converts every `<time datetime>` on the page to the viewer's local time
             zone client-side; the server-rendered text stays as the no-JS fallback.
             Lifted from the customer status page's shell, because a status document
             that prints UTC to somebody in another zone is asking them to do
             arithmetic about an outage. --}}
        <script>
            document.querySelectorAll('time[datetime]').forEach(function (el) {
                el.textContent = new Date(el.dateTime).toLocaleString();
            });
        </script>
    </body>
</html>
