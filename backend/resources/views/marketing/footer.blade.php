{{--
    The footer.

    Deliberately short. A footer template arrives with Pricing, Docs, Careers, Privacy
    and four social icons, and every one of those is a claim that a page exists. This
    deployment serves the landing page, the four documents below, the public status
    pages and the client, so those are the only things linked. `ChromeTest` pins the
    absence of the rest, and the rule that governs both files is one rule: a link and
    the page behind it land in the same change, never a release apart.
--}}
{{-- `surface` rather than `surface-container`, so the alternation the sections keep does
     not break on the last step: the closing band above is a container, and two identical
     tones in a row is where the bottom of the page stops having a seam. It also puts the
     footer on the page's base tone, which is the conventional recessed treatment. --}}
<footer class="border-t border-border bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:px-8">
        {{-- Three columns, and the language list is deliberately NOT a fourth.
             Discovering the other language does not depend on it: the <head> carries a
             full hreflang set including x-default, which is the signal a crawler
             actually follows, and the header renders the same links in the markup with
             no JavaScript in the way. A fourth column here only repeated them. --}}
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-3">
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

            {{-- The four long-form documents.

                 The href is COMPOSED, from `$homePath` (the chrome's own home path for
                 the language being read: `/` or `/tr`), rather than typed as `/privacy`.
                 A Turkish reader clicking "Gizlilik" must land on `/tr/privacy` and not
                 be dropped into the English notice, and `rtrim` is what keeps the default
                 language on `/privacy` instead of `//privacy`.

                 Typed as a list here because there is no config or enum behind these four
                 paths to derive them from; the routes themselves are the same typed list.
                 What is NOT negotiable is that a link here is a promise the page answers:
                 all four were absent from this footer until the pages existed, and
                 `ChromeTest` still pins the absence of every footer cliche with nothing
                 behind it. --}}
            <div>
                <h2 class="text-label-sm uppercase tracking-[0.12em] text-fg-muted">{{ __('Legal') }}</h2>

                <ul class="mt-3 space-y-2">
                    @foreach ([
                        ['path' => 'privacy', 'label' => __('Privacy')],
                        ['path' => 'terms', 'label' => __('Terms')],
                        ['path' => 'contact', 'label' => __('Contact')],
                        ['path' => 'faq', 'label' => __('FAQ')],
                    ] as $documentLink)
                        <li>
                            <a
                                href="{{ rtrim($homePath, '/').'/'.$documentLink['path'] }}"
                                class="text-body-md text-fg-muted transition-colors hover:text-fg"
                            >{{ $documentLink['label'] }}</a>
                        </li>
                    @endforeach

                    {{-- Withdrawal, and it belongs here rather than buried in the notice.
                         GDPR Art. 7(3): withdrawing consent has to be as easy as giving it,
                         so the way back is one click from every page, next to the documents
                         that describe it.

                         A BUTTON and not an anchor, deliberately. A `href="#..."` here would
                         have to resolve to an element carrying that id (`ChromeTest` and
                         `LayoutTest` walk every fragment on the page and fail the build on a
                         dangling one), and the banner is a dialog rather than a place on the
                         page. The dispatched event bubbles to the window, where the banner
                         listens for it.

                         Present only when there is something to withdraw: with no container
                         configured nothing is ever stored, so the link would be offering to
                         change a choice that was never asked for. --}}
                    @if ($consent['container_id'] !== null)
                        <li>
                            <button
                                type="button"
                                x-data
                                x-on:click="$dispatch('consent-reopen')"
                                class="text-body-md text-fg-muted transition-colors hover:text-fg"
                            >{{ __('Change your choice') }}</button>
                        </li>
                    @endif
                </ul>
            </div>

        </div>

        <div class="mt-10 flex flex-col gap-2 border-t border-border-subtle pt-6 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-label-sm text-fg-muted">© {{ now()->year }} {{ config('app.name') }}</p>
            <p class="text-label-sm text-fg-muted">{{ $platformClaim }}</p>
        </div>
    </div>
</footer>
