{{--
    The closing band.

    Every page in the category repeats the free-tier hook at the bottom, and it is the one
    piece of expected furniture here that costs nothing to give: a reader who scrolled this
    far has read four arguments and should not have to scroll back up to act on them.

    The free-tier sentence is the same derived one the hero uses, from the plan catalog, so
    the page cannot promise a limit at the bottom that it contradicts at the top.
--}}
{{-- `surface-container`, so the page alternates all the way down: hero, container,
     surface, container, surface, container. Two identical backgrounds in a row is where
     a long page stops reading as sections and starts reading as one wall. --}}
<section class="border-b border-border bg-surface-container">
    <div data-reveal class="mx-auto max-w-3xl px-4 py-20 text-center sm:px-6 lg:px-8 lg:py-28">
        <h2 class="text-section text-balance text-fg">
            {{ __('Point it at one URL and') }}
            <span class="text-primary">{{ __('see what comes back') }}</span>.
        </h2>

        <p class="mx-auto mt-5 max-w-xl text-body-lg text-fg-muted">
            {{ __('Nothing to install, and nothing to undo if you change your mind. The first monitor takes about as long as reading this sentence.') }}
        </p>

        <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
            <a
                href="{{ $signUpUrl }}"
                class="inline-flex min-h-12 items-center rounded-md bg-primary px-6 text-label-md text-on-primary transition-opacity hover:opacity-90"
            >{{ __('Start free') }}</a>

            <a
                href="{{ $signInUrl }}"
                class="inline-flex min-h-12 items-center rounded-md border border-border px-6 text-label-md text-fg transition-colors hover:bg-surface-container-high"
            >{{ __('Sign in') }}</a>
        </div>

        @if ($freeTier !== null && $freeTier['monitors'] !== null)
            <p class="mt-6 text-body-md text-fg-muted">
                {{ trans_choice('{1}Free plan: one monitor on :interval checks, one status page. No card.|[2,*]Free plan: :count monitors on :interval checks, :pages status pages. No card.', $freeTier['monitors'], [
                    'count' => $freeTier['monitors'],
                    'interval' => $freeTier['interval'],
                    'pages' => $freeTier['status_pages'],
                ]) }}
            </p>
        @endif
    </div>
</section>
