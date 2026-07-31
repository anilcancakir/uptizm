{{--
    Hero. Two columns on wide viewports: the argument on the left, an artifact on
    the right.

    The artifact is a rendering of the product's own interface rather than an
    illustration, because in this category a drawing of a green tick is clip art
    and a real panel is the only thing that reads as evidence. It is built from
    the same tokens the Flutter client uses, so it cannot drift from the product
    the way a screenshot would.

    It is an EXAMPLE, not live data: the host is a placeholder domain and the
    panel carries an aria-label saying so. The numbers are plausible rather than
    measured, which is why nothing here is framed as our own uptime.
--}}
<section class="relative overflow-hidden border-b border-border">
    {{-- A single soft brand wash behind the artifact, drawn as a radial gradient
         rather than a blurred element. Two reasons, and the second is the real
         one: a 64px blur radius crashed the headless Chromium that renders our
         own status-page previews, and Wind supports no filter utilities at all,
         so a blur here would be a effect the product itself cannot express. --}}
    <div
        class="pointer-events-none absolute -top-40 right-[-10%] hidden size-[46rem] lg:block"
        style="background: radial-gradient(closest-side, var(--app-primary) 0%, transparent 70%); opacity: 0.07"
        aria-hidden="true"
    ></div>

    <div class="relative mx-auto grid max-w-6xl gap-14 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1.15fr_0.95fr] lg:items-center lg:gap-14 lg:px-8">
        <div>
            <p data-enter class="flex items-center gap-2.5 text-label-sm uppercase tracking-[0.14em] text-fg-muted">
                <span class="relative flex size-2">
                    <span class="absolute inline-flex size-full rounded-full bg-up opacity-60 motion-safe:animate-ping"></span>
                    <span class="relative inline-flex size-2 rounded-full bg-up"></span>
                </span>
                {{ __('Region-pinned probes at the edge') }}
            </p>

            <h1 data-enter style="animation-delay: 60ms" class="mt-5 text-hero text-balance text-fg">
                {{ __('Uptime monitoring that refuses to guess.') }}
            </h1>

            <p data-enter style="animation-delay: 120ms" class="mt-6 max-w-xl text-body-lg text-fg-muted">
                {{ __('HTTP and TCP checks from :count pinned regions, an incident that opens on repeated failure rather than the first blip, and a status page that says "no data" instead of inventing a green tick.', ['count' => count($regions)]) }}
            </p>

            <div data-enter style="animation-delay: 180ms" class="mt-9 flex flex-wrap items-center gap-3">
                <a
                    href="{{ $signUpUrl }}"
                    class="inline-flex min-h-12 items-center rounded-md bg-primary px-6 text-label-md text-on-primary transition-opacity hover:opacity-90"
                >{{ __('Start free') }}</a>

                <a
                    href="#pipeline"
                    class="inline-flex min-h-12 items-center rounded-md border border-border px-6 text-label-md text-fg transition-colors hover:bg-surface-container-high"
                >{{ __('How a check becomes a page') }}</a>
            </div>

            @if ($freeTier !== null && $freeTier['monitors'] !== null)
                <p data-enter style="animation-delay: 240ms" class="mt-5 text-body-md text-fg-muted">
                    {{ trans_choice('{1}Free plan: one monitor on :interval checks, one status page. No card.|[2,*]Free plan: :count monitors on :interval checks, :pages status pages. No card.', $freeTier['monitors'], [
                        'count' => $freeTier['monitors'],
                        'interval' => $freeTier['interval_label'],
                        'pages' => $freeTier['status_pages'],
                    ]) }}
                </p>
            @endif
        </div>

        @include('marketing.partials.hero-panel')
    </div>
</section>
