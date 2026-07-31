{{--
    The footer.

    Deliberately short. A footer template arrives with Pricing, Docs, Careers, Privacy
    and four social icons, and every one of those is a claim that a page exists. This
    deployment serves the landing page, the public status pages and the client, so
    those are the only things linked. `ChromeTest` pins the absence of the rest.

    Privacy and Terms are a real launch requirement rather than an oversight: the
    status pages collect subscriber email and Stripe is wired. They are not linked
    here because they are not written yet, and a 404 behind "Privacy" is worse than
    no link at all.
--}}
{{-- `surface` rather than `surface-container`, so the alternation the sections keep does
     not break on the last step: the closing band above is a container, and two identical
     tones in a row is where the bottom of the page stops having a seam. It also puts the
     footer on the page's base tone, which is the conventional recessed treatment. --}}
<footer class="border-t border-border bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <div class="flex items-center gap-2.5">
                    @include('marketing.brand-mark')
                    <span class="text-title-lg text-fg">{{ config('app.name') }}</span>
                </div>

                <p class="mt-3 max-w-sm text-body-md text-fg-muted">
                    {{ __('Uptime, incident and status-page monitoring from :count regions.', ['count' => count($regions)]) }}
                </p>
            </div>

            @if ($sections !== [])
                <div>
                    <h2 class="text-label-sm uppercase tracking-[0.12em] text-fg-muted">{{ __('Product') }}</h2>

                    <ul class="mt-3 space-y-2">
                        @foreach ($sections as $section)
                            <li>
                                <a
                                    href="#{{ $section['id'] }}"
                                    class="text-body-md text-fg-muted transition-colors hover:text-fg"
                                >{{ $section['label'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <h2 class="text-label-sm uppercase tracking-[0.12em] text-fg-muted">{{ __('Account') }}</h2>

                <ul class="mt-3 space-y-2">
                    <li>
                        <a
                            href="{{ $signInUrl }}"
                            class="text-body-md text-fg-muted transition-colors hover:text-fg"
                        >{{ __('Sign in') }}</a>
                    </li>
                    <li>
                        <a
                            href="{{ $signUpUrl }}"
                            class="text-body-md text-fg-muted transition-colors hover:text-fg"
                        >{{ __('Start free') }}</a>
                    </li>
                </ul>
            </div>

            {{-- The full list as plain links, not a menu. This is the copy a crawler
                 follows to discover the other language, so it must be in the markup
                 with no interaction and no JavaScript in the way. --}}
            <div>
                <h2 class="text-label-sm uppercase tracking-[0.12em] text-fg-muted">{{ __('Language') }}</h2>

                <ul class="mt-3 space-y-2">
                    @foreach ($localeLinks as $link)
                        <li>
                            <a
                                href="{{ $link['path'] }}"
                                hreflang="{{ $link['code'] }}"
                                lang="{{ $link['code'] }}"
                                @if ($link['current']) aria-current="true" @endif
                                class="text-body-md text-fg-muted transition-colors hover:text-fg aria-[current=true]:text-fg"
                            >{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="mt-10 flex flex-col gap-2 border-t border-border-subtle pt-6 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-label-sm text-fg-muted">© {{ now()->year }} {{ config('app.name') }}</p>
            <p class="text-label-sm text-fg-muted">{{ $platformClaim }}</p>
        </div>
    </div>
</footer>
