{{--
    The stage: one frame, four acts, on a loop.

    Structure adapted from fluttersdk.com's hero workflow. A fixed-height content area
    so swapping an act cannot shift the layout, a top bar whose label and dot follow the
    current act, and the tilt applied to a wrapper so the perspective has something to
    sit in.

    The act tabs are real buttons. A visitor who does not want to wait through 25
    seconds can jump, a keyboard user can reach every act, and the hero describes
    itself even in a screenshot where nothing is playing.
--}}
<div class="stage-perspective" x-data="heroSequence" x-cloak>
    <div class="stage-tilt">
        {{-- Glow. A radial gradient, not a blurred element: see the note in app.css. --}}
        <div
            class="pointer-events-none absolute -inset-6 -z-10"
            style="background: radial-gradient(closest-side, var(--app-primary) 0%, transparent 72%); opacity: 0.12"
            aria-hidden="true"
        ></div>

        <div
            role="img"
            :aria-label="'{{ __('Example: Uptizm checking an endpoint, catching a slow response and paging on call.') }}'"
            class="stage-shine relative overflow-hidden rounded-lg border border-border bg-surface-container"
        >
            {{-- Top bar. The dot takes the act's colour, the label its name. --}}
            <div class="flex items-center gap-3 border-b border-border-subtle px-4 py-3">
                <span class="flex gap-1.5" aria-hidden="true">
                    <span class="size-2.5 rounded-full bg-fg-disabled"></span>
                    <span class="size-2.5 rounded-full bg-fg-disabled"></span>
                    <span class="size-2.5 rounded-full bg-fg-disabled"></span>
                </span>

                <span class="flex min-w-0 flex-1 items-center justify-center gap-2 rounded-base bg-surface-container-high px-3 py-1 font-mono text-label-sm text-fg-muted">
                    <span
                        class="size-1.5 rounded-full transition-colors"
                        :style="`background-color: var(--app-${act === 3 ? 'degraded' : act === 4 ? 'ai' : 'primary'})`"
                    ></span>
                    <span x-text="labels[act] ?? labels[1]">{{ __('New monitor') }}</span>
                </span>
            </div>

            {{-- Fixed height: an act swap must never move the page. --}}
            <div class="relative h-[24rem]">
                @include('marketing.acts.setup')
                @include('marketing.acts.dispatch')
                @include('marketing.acts.checks')
                @include('marketing.acts.triage')
                @include('marketing.acts.delivery')
            </div>

            {{-- Act tabs. --}}
            <div class="flex items-center gap-1 border-t border-border-subtle p-2">
                @foreach ([
                    1 => __('Set it up'),
                    2 => __('Checked at once'),
                    3 => __('Read and triaged'),
                    4 => __('Delivered'),
                ] as $n => $label)
                    <button
                        type="button"
                        x-on:click="showAct({{ $n }})"
                        :aria-current="Math.floor(act) === {{ $n }} ? 'step' : 'false'"
                        class="flex-1 rounded-base px-2 py-2 text-label-sm text-fg-muted transition-colors hover:text-fg aria-[current=step]:bg-surface-container-high aria-[current=step]:text-fg"
                    >{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>
</div>
