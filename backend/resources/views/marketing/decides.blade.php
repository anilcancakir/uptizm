{{--
    Section 2: the decision rules, published.

    This is the page's competitive wedge and the reason it is second rather than
    further down. Better Stack's uptime page headlines "no false positives" and leaves
    it there, which is where every tool in this category stops: an assertion that you
    are asked to trust. The thing we can actually do differently is print the rule that
    produces the verdict, and let a reader check it against their own experience of
    being paged at 3am for nothing.

    Every rule comes from the controller, read out of the code that enforces it, and
    each carries its real mechanism in mono. The mechanism line is the proof device: it
    is the same string an engineer would grep for, which is a different kind of claim
    from a sentence in a marketing voice.

    Motion is the page-wide scroll reveal and nothing more: the header, then the four
    cards in order, then the region strip. Deliberately the same arrival every other
    section uses, because a stagger that is identical everywhere reads as intent while
    four clever per-section effects read as four different afternoons.
--}}
<section id="how-it-decides" class="border-b border-border bg-surface-container">
    <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
        <div data-reveal class="max-w-2xl">
            <p class="text-label-md text-accent">{{ __('How it decides') }}</p>

            <h2 class="mt-3 text-section text-balance text-fg">
                {{ __('Every alert has a rule behind it.') }}
                <span class="text-primary">{{ __('Here they are') }}</span>.
            </h2>

            <p class="mt-5 text-body-lg text-fg-muted">
                {{ __('Every monitoring tool promises it will not wake you for nothing. These are the four rules that decide, printed rather than promised, so you can check them against the last false alarm you were paged for.') }}
            </p>
        </div>

        <ol class="mt-14 grid gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-2">
            @foreach ($decisionRules as $i => $rule)
                <li data-reveal style="--reveal-index: {{ $i + 1 }}" class="flex flex-col gap-3 bg-surface p-6 lg:p-8">
                    <div class="flex items-baseline gap-3">
                        {{-- The index is decoration, so it is hidden from the reading
                             order: the ordered list already numbers these for anyone
                             not looking at the screen. --}}
                        <span class="font-mono text-label-sm text-fg-disabled" aria-hidden="true">
                            {{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}
                        </span>

                        <h3 class="text-title-lg text-fg">{{ $rule['title'] }}</h3>
                    </div>

                    <p class="text-body-md text-fg-muted">{{ $rule['body'] }}</p>

                    {{-- The mechanism, in the mono face the app uses for every measured
                         value. Not translated: it is the real identifier, and a
                         translated `consecutive_fails` would stop being checkable. --}}
                    <p class="mt-auto pt-2">
                        <code class="rounded-base bg-surface-container-high px-2 py-1 font-mono text-label-sm text-fg-muted">{{ $rule['mechanism'] }}</code>
                    </p>
                </li>
            @endforeach
        </ol>

        {{-- The regions, as the last word of the section. The names come from the enum
             the write requests validate against, so this strip cannot advertise a region
             a monitor could not actually be pinned to. --}}
        <div data-reveal style="--reveal-index: 5" class="mt-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-body-md text-fg-muted">
                {{ __('And every check records which data centre answered it, so a regional story is evidence rather than a guess.') }}
            </p>

            <ul class="flex flex-wrap gap-2">
                @foreach ($regions as $region)
                    <li class="rounded-full border border-border px-3 py-1 font-mono text-label-sm text-fg-muted">{{ $region['label'] }}</li>
                @endforeach
            </ul>
        </div>
    </div>
</section>
