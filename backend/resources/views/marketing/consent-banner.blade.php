{{--
    The consent banner.

    IT EXISTS ONLY BECAUSE ANALYTICS DOES

    Same `@if` as the head bootstrap: with no container id there is nothing that stores
    anything, so there is nothing to ask about and this file emits nothing at all. The
    banner and the thing it gates land together or neither lands, which is the same rule the
    footer's links follow.

    WHY IT IS NOT SERVER-DECIDED

    "No choice stored yet" is a fact about the visitor's device, and the server never reads
    that record (config/analytics.php: keeping it out of a cookie is what lets these pages
    keep sending no `Set-Cookie`). So the markup is always rendered when analytics is
    configured, and the component decides on boot whether to show it. `x-cloak` plus the
    repo's `[x-cloak] { display: none !important; }` is what stops a returning visitor from
    seeing a flash of a banner they already answered.

    That also means the banner needs JavaScript to appear, which is coherent rather than a
    gap: with scripting off `gtm.js` never loads, nothing is stored, and there is nothing to
    consent to.

    THE TWO BUTTONS ARE THE SAME BUTTON

    They are rendered from one loop over one class string, so `Accept all` cannot drift into
    being the loud one. A bright primary next to a grey link is the asymmetry the CNIL has
    fined and the EDPB treats as invalid consent, and it is the exact thing a later "let us
    improve the conversion here" edit would reintroduce. Making the two visually identical
    STRUCTURALLY is the only version of this that survives that edit.

    WHY THERE IS NO THIRD BUTTON NEXT TO THE TOGGLE

    Analytics is the only optional category, so the switch cannot express a state the two
    buttons do not already commit: switch on IS accept all, switch off IS necessary only. A
    "save my choices" button would be a third path to the same two outcomes. Add a second
    optional category and that stops being true, which is the moment to add one (and to bump
    `consent_version`, since the old answers would no longer cover the new question).
--}}
@if ($consent['container_id'] !== null)
    <div
        id="consent-banner"
        role="dialog"
        aria-labelledby="consent-banner-title"
        aria-describedby="consent-banner-body"
        tabindex="-1"
        x-ref="dialog"
        x-data="consentChoice({ storageKey: @js($consent['storage_key']), version: @js($consent['version']) })"
        x-show="open"
        x-cloak
        {{-- Escape closes and stores NOTHING: a dismissal is not an answer, so Consent Mode
             stays denied and the banner asks again on the next page. Treating Escape (or a
             scroll, or continued browsing) as acceptance is the thing that makes a consent
             record worthless. --}}
        x-on:keydown.escape="dismiss()"
        {{-- Reopened from the footer, one bubbled event away. GDPR Art. 7(3): withdrawal has
             to be as easy as giving, so there is exactly one control for both. --}}
        x-on:consent-reopen.window="reopen()"
        x-transition:enter="consent-fade"
        x-transition:enter-start="consent-fade-out"
        x-transition:enter-end="consent-fade-in"
        x-transition:leave="consent-fade"
        x-transition:leave-start="consent-fade-in"
        x-transition:leave-end="consent-fade-out"
        {{-- Fixed, so it costs no layout shift: it is painted over the page rather than
             inserted into it, and the content underneath never moves. --}}
        class="fixed inset-x-0 bottom-0 z-[70] border-t border-border bg-surface-container"
    >
        <div class="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-5 sm:px-6 lg:flex-row lg:items-start lg:gap-8 lg:px-8">
            <div class="lg:flex-1">
                <h2 id="consent-banner-title" class="text-title-lg text-fg">{{ __('Cookies for analytics') }}</h2>

                {{-- Honest about what consent does and does not gate here: it gates STORAGE
                     on this device. The request to Google happens either way, and the
                     Privacy notice is where that is spelled out. --}}
                <p id="consent-banner-body" class="mt-1 max-w-2xl text-body-md text-fg-muted">
                    {{ __('Uptizm sets no cookies of its own. If you allow analytics, Google Analytics stores a cookie on this device so we can count visits. Until you choose, nothing is stored.') }}
                </p>

                {{-- The reader's own language, composed from the chrome's home path exactly
                     as the footer composes its four documents: a Turkish reader lands on
                     `/tr/privacy`, because consent given against a notice you cannot read is
                     not informed consent. --}}
                <a
                    data-consent-privacy
                    href="{{ rtrim($homePath, '/').'/privacy' }}"
                    class="mt-2 inline-block text-body-md text-fg underline transition-opacity hover:opacity-80"
                >{{ __('Privacy notice') }}</a>

                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:gap-8">
                    {{-- Necessary: stated, never offered. There is no control here because
                         there is no choice to make; the only thing in this category is the
                         record of the choice itself, which Art. 7(1) requires us to keep and
                         which therefore needs no consent of its own. --}}
                    <div class="sm:flex-1">
                        <p class="text-label-md text-fg">
                            {{ __('Necessary') }}
                            <span class="text-label-sm text-fg-muted">{{ __('Always on') }}</span>
                        </p>
                        <p class="mt-0.5 text-label-sm text-fg-muted">
                            {{ __('The record of this choice, and nothing else. No cookie.') }}
                        </p>
                    </div>

                    <div class="sm:flex-1">
                        <div class="flex items-center gap-2">
                            {{-- A real switch, off in the SERVER markup and not merely off in
                                 the component's state: pre-ticked analytics is an assumption
                                 of consent, and the markup is what a visitor sees first. --}}
                            <button
                                type="button"
                                role="switch"
                                aria-checked="false"
                                :aria-checked="analytics ? 'true' : 'false'"
                                aria-labelledby="consent-analytics-label"
                                x-on:click="analytics = ! analytics"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border border-border transition-colors"
                                :class="analytics ? 'bg-primary' : 'bg-surface-container-high'"
                            >
                                <span
                                    class="size-4 rounded-full bg-fg transition-transform"
                                    :class="analytics ? 'translate-x-6' : 'translate-x-1'"
                                ></span>
                            </button>

                            <span id="consent-analytics-label" class="text-label-md text-fg">{{ __('Analytics') }}</span>
                        </div>

                        <p class="mt-0.5 text-label-sm text-fg-muted">
                            {{ __('Counts visits with Google Analytics. Off until you turn it on.') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- One loop, one class string, two outcomes. See the file comment: the equal
                 prominence is the legal requirement, and a loop is what keeps it true. --}}
            <div class="flex flex-col gap-3 sm:flex-row lg:shrink-0 lg:pt-1">
                @foreach ([
                    ['label' => __('Accept all'), 'action' => 'choose(true)'],
                    ['label' => __('Necessary only'), 'action' => 'choose(false)'],
                ] as $consentAction)
                    <button
                        type="button"
                        x-on:click="{{ $consentAction['action'] }}"
                        class="inline-flex min-h-11 items-center justify-center rounded-md border border-border bg-surface-container-high px-5 text-label-md text-fg transition-colors hover:bg-surface sm:min-w-44"
                    >{{ $consentAction['label'] }}</button>
                @endforeach
            </div>
        </div>
    </div>
@endif
