{{--
    Closing call to action. Same verb as the header and the hero: one offer,
    stated three times, rather than three offers.

    No price table anywhere on this page. Paid tiers are not self-serve yet, and
    a price with a button that cannot complete a checkout is worse than no price.
--}}
<section class="border-b border-border bg-surface-container">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-section text-balance text-fg">{{ __('Put one monitor up and see what it tells you.') }}</h2>

            <p class="mt-4 text-body-lg text-fg-muted">
                {{ __('A URL, an interval, and the regions to watch from. The first check runs on the next tick.') }}
            </p>

            <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
                <a
                    href="{{ $signUpUrl }}"
                    class="inline-flex min-h-12 items-center rounded-md bg-primary px-6 text-label-md text-on-primary transition-opacity hover:opacity-90"
                >{{ __('Start free') }}</a>

                <a
                    href="{{ $signInUrl }}"
                    class="inline-flex min-h-12 items-center rounded-md border border-border px-6 text-label-md text-fg transition-colors hover:bg-surface"
                >{{ __('Sign in') }}</a>
            </div>
        </div>
    </div>
</section>
