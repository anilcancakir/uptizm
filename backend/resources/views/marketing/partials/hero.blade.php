{{--
    Hero. The argument on the left, the product on the right: a desktop panel in
    window chrome with the mobile client overlapping its corner, because "one
    product on every platform" is a claim better shown than written.

    Depth here is a dot grid and two low-opacity radial washes, no blur and no
    shadow. Wind supports no filter utilities at all, so a blurred element would
    be an effect the product itself cannot express, and a 64px blur radius also
    crashed the headless Chromium we render status-page previews with.
--}}
<section class="relative overflow-hidden border-b border-border">
    {{-- Dot grid, fading out downward so it reads as texture behind the headline
         rather than as a pattern the page is sitting on. --}}
    <div
        class="pointer-events-none absolute inset-0 opacity-[0.55]"
        style="
            background-image: radial-gradient(var(--app-border) 1px, transparent 1px);
            background-size: 28px 28px;
            mask-image: linear-gradient(to bottom, black, transparent 62%);
            -webkit-mask-image: linear-gradient(to bottom, black, transparent 62%);
        "
        aria-hidden="true"
    ></div>

    <div
        class="pointer-events-none absolute -top-40 right-[-8%] hidden size-[46rem] lg:block"
        style="background: radial-gradient(closest-side, var(--app-primary) 0%, transparent 70%); opacity: 0.1"
        aria-hidden="true"
    ></div>

    <div
        class="pointer-events-none absolute bottom-[-22rem] left-[-10rem] hidden size-[34rem] lg:block"
        style="background: radial-gradient(closest-side, var(--app-primary) 0%, transparent 72%); opacity: 0.07"
        aria-hidden="true"
    ></div>

    <div class="relative mx-auto grid max-w-6xl gap-16 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1.12fr_1fr] lg:items-center lg:gap-12 lg:px-8">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($aiEnabled)
                    @include('marketing.partials.eyebrow', ['text' => __('AI-assisted triage'), 'tone' => 'ai'])
                @endif
                @include('marketing.partials.eyebrow', ['text' => __('Web, iOS and Android')])
            </div>

            <h1 data-enter style="animation-delay: 60ms" class="mt-6 text-hero text-balance text-fg">
                {{ __('Uptime monitoring that') }}
                {{-- The accent lands on the promise, not on the category. --}}
                <span class="text-primary">{{ __('refuses to guess') }}</span>.
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
                    class="group inline-flex min-h-12 items-center gap-2 rounded-md border border-border px-6 text-label-md text-fg transition-colors hover:bg-surface-container-high"
                >
                    {{ __('How a check becomes a page') }}
                    <span class="text-fg-muted transition-transform group-hover:translate-x-0.5" aria-hidden="true">&rarr;</span>
                </a>
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

        <div class="relative">
            @include('marketing.partials.hero-panel')
        </div>
    </div>
</section>
