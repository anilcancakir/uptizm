{{--
    The hero.

    Height is clamped rather than set to 100dvh. A viewport-filling hero reads as a
    finished page and gets under-scrolled (the "illusion of completeness"), so this
    deliberately leaves the next section peeking. `dvh` rather than `vh` so mobile
    browser chrome appearing and disappearing does not resize it mid-scroll.

    The left column NEVER moves after its entrance. Copy that animates is what makes
    a page feel cheap; all the motion lives in the panel on the right, which is the
    thing actually worth watching.
--}}
{{-- `flex items-center` on the section, not just on the grid inside it.
     The grid carried `h-full items-center` for a while and centred nothing: `h-full`
     resolves against a parent whose height is `auto` (this section only sets
     min-height), so it collapsed to the content height and left ~265px of dead space
     below the fold. The section has to be the flex container for the centring to have
     anything to centre against. --}}
<section
    class="relative flex items-center overflow-hidden border-b border-border"
    style="min-height: clamp(700px, 90dvh, 980px)"
>
    {{-- Dot grid, masked to fade downward so it reads as texture behind the
         headline rather than a pattern the page sits on. --}}
    <div
        class="pointer-events-none absolute inset-0 opacity-60"
        style="
            background-image: radial-gradient(var(--app-border) 1px, transparent 1px);
            background-size: 30px 30px;
            mask-image: linear-gradient(to bottom, black, transparent 70%);
            -webkit-mask-image: linear-gradient(to bottom, black, transparent 70%);
        "
        aria-hidden="true"
    ></div>

    {{-- One brand wash, as a radial gradient. Not a blurred element: Wind supports
         no filter utilities, so a blur here would be an effect the product itself
         cannot express, and a large blur radius crashed the headless Chromium we
         render status-page previews with. --}}
    <div
        class="pointer-events-none absolute -top-48 right-[-12%] hidden size-[48rem] lg:block"
        style="background: radial-gradient(closest-side, var(--app-primary) 0%, transparent 70%); opacity: 0.1"
        aria-hidden="true"
    ></div>

    <div class="relative mx-auto grid w-full max-w-6xl items-center gap-14 px-4 py-16 sm:px-6 lg:grid-cols-[1.04fr_1fr] lg:gap-12 lg:px-8">
        <div>
            {{-- Sentence case, not uppercase.
                 `text-transform: uppercase` is locale-aware, and under `lang="tr"` a
                 dotless `i` becomes `İ`. That is correct Turkish for Turkish words and
                 wrong for a brand name: the platform pill rendered "İOS", which reads
                 as a typo. Rather than lie about the element's language to defeat the
                 mapping, these pills simply keep their own case. --}}
            <div class="flex flex-wrap items-center gap-2">
                @if ($aiEnabled)
                    <span data-enter class="inline-flex items-center gap-2 rounded-full bg-ai-soft px-3 py-1 text-label-md text-ai-soft-foreground">
                        <span data-breathe class="size-2 rounded-full bg-current"></span>
                        {{ __('AI-assisted triage') }}
                    </span>
                @endif

                <span data-enter style="--enter-index: 1" class="inline-flex items-center gap-2 rounded-full bg-primary-container px-3 py-1 text-label-md text-accent">
                    <span class="size-2 rounded-full bg-current"></span>
                    {{ $platformClaim }}
                </span>
            </div>

            <h1 data-enter style="--enter-index: 2" class="mt-6 text-hero text-balance text-fg">
                {{ __('Uptime monitoring that') }}
                <span class="text-primary">{{ __('refuses to guess') }}</span>.
            </h1>

            <p data-enter style="--enter-index: 3" class="mt-6 max-w-xl text-body-lg text-fg-muted">
                {{ __('Every region is checked at the same moment, an incident opens on repeated failure rather than the first blip, and the numbers you are shown are the ones that were measured.', ['count' => count($regions)]) }}
            </p>

            {{-- One call to action, not two.
                 There was a second button here reading "See how it decides", pointing
                 at `#pipeline`. No section with that id exists yet, so clicking it did
                 nothing at all: a dead link shipped for several commits because a
                 static screenshot cannot show you that a jump went nowhere.
                 It comes back with the section it names, and `ChromeTest` now fails
                 the build on any anchor whose target is missing. --}}
            <div data-enter style="--enter-index: 4" class="mt-9 flex flex-wrap items-center gap-3">
                <a
                    href="{{ $signUpUrl }}"
                    class="inline-flex min-h-12 items-center rounded-md bg-primary px-6 text-label-md text-on-primary transition-opacity hover:opacity-90"
                >{{ __('Start free') }}</a>

                <a
                    href="{{ $signInUrl }}"
                    class="group inline-flex min-h-12 items-center gap-2 rounded-md border border-border px-6 text-label-md text-fg transition-colors hover:bg-surface-container-high"
                >
                    {{ __('Sign in') }}
                    <span class="text-fg-muted transition-transform group-hover:translate-x-0.5" aria-hidden="true">&rarr;</span>
                </a>
            </div>

            @if ($freeTier !== null && $freeTier['monitors'] !== null)
                <p data-enter style="--enter-index: 5" class="mt-5 text-body-md text-fg-muted">
                    {{ trans_choice('{1}Free plan: one monitor on :interval checks, one status page. No card.|[2,*]Free plan: :count monitors on :interval checks, :pages status pages. No card.', $freeTier['monitors'], [
                        'count' => $freeTier['monitors'],
                        'interval' => $freeTier['interval'],
                        'pages' => $freeTier['status_pages'],
                    ]) }}
                </p>
            @endif
        </div>

        <div class="relative">
            @include('marketing.hero-stage')
        </div>
    </div>
</section>
