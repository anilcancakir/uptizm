{{--
    Section 5: the AI, sold on its limits.

    Written against a specific research finding. Checkly's page has drifted so far into AI
    framing that whole sections read as buzzwords to somebody who came for uptime checks,
    and that is the standing risk for any AI claim in this category: it is unfalsifiable,
    so it reads as noise. The escape is to make the claim falsifiable, and the only
    falsifiable thing about an AI feature is what it refuses to do.

    Which is also the truth here, and it is already written down as load-bearing in
    docs/uptizm-system/product.md: uptizm has no integration into the customer's product.
    No deploys, no git, no CI, no logs, no APM, no CDN. So its AI reasons only from what
    uptizm itself measured, and it cannot produce the sentence every competing AI feature
    produces: "errors started two minutes after deploy a1b2c3". Saying that out loud is
    worth more than any capability claim, because a reader who has been burned by a
    confident wrong root cause recognises it immediately.

    The whole section is withheld when this deployment has no provider key, along with its
    nav entry. Without a key every AI path returns its deterministic fallback, and a
    section advertising analysis on top of the fallback would be selling the fallback.
--}}
@if ($aiEnabled)
    <section id="ai-boundary" class="border-b border-border">
        <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div data-reveal class="max-w-2xl">
                <p class="text-label-md" style="color: var(--app-ai)">{{ __('The AI boundary') }}</p>

                <h2 class="mt-3 text-section text-balance text-fg">
                    {{ __('It reads what we measured, and') }}
                    <span style="color: var(--app-ai)">{{ __('nothing else') }}</span>.
                </h2>

                <p class="mt-5 text-body-lg text-fg-muted">
                    {{ __('An AI that guesses at a cause is worse than no AI at all, because you act on it at three in the morning. So this one is fenced: it cites the checks, the regions and the metrics it can see, it argues both sides, and where it does not know it says so.') }}
                </p>
            </div>

            <div class="mt-14 grid gap-10 lg:grid-cols-2 lg:gap-16">
                {{-- The never-list, and it is the section's centrepiece rather than a
                     footnote. Nobody in this category publishes theirs. --}}
                <div data-reveal style="--reveal-index: 1; border-color: var(--app-ai-soft); background-color: var(--app-ai-wash)" class="rounded-lg border p-6 lg:p-8">
                    <h3 class="text-title-lg text-fg">{{ __('What it cannot see, and will never claim to') }}</h3>

                    <ul class="mt-5 flex flex-col gap-3">
                        @foreach ($aiBoundary['cannot'] as $item)
                            <li class="flex items-start gap-3 text-body-md text-fg-muted">
                                <svg viewBox="0 0 24 24" class="mt-1 size-4 shrink-0" style="color: var(--app-ai)" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
                                </svg>
                                {{ $item }}
                            </li>
                        @endforeach
                    </ul>

                    <p class="mt-6 border-t pt-5 text-body-md text-fg-muted" style="border-color: var(--app-ai-soft)">
                        {{ __('Uptizm watches your endpoints from the outside. That is the whole of what it knows, so "your queue crossed its bound three checks before the first 503" is something it can show you, and "your last deploy caused this" is not.') }}
                    </p>
                </div>

                {{-- The modes, from the enum the write requests validate against. Framed
                     as graduated trust: the point is that you choose how far in it gets,
                     and that Off is a real setting rather than a way of saying "unused". --}}
                <div data-reveal style="--reveal-index: 2">
                    <h3 class="text-title-lg text-fg">{{ __('You decide how far in it gets') }}</h3>

                    <dl class="mt-5 flex flex-col gap-5">
                        @foreach ($aiBoundary['modes'] as $mode)
                            <div class="border-l-2 pl-5" style="border-color: var(--app-ai-soft)">
                                <dt class="font-mono text-label-md text-fg">{{ $mode['name'] }}</dt>
                                <dd class="mt-1 text-body-md text-fg-muted">{{ $mode['body'] }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <p class="mt-7 text-body-md text-fg-muted">
                        {{ __('Its suggestions stay advisory: there is no button that lets it act on your infrastructure, because it has no way to reach it.') }}
                    </p>
                </div>
            </div>

            {{-- The pricing note belongs in this section rather than on the pricing page,
                 because it is an argument about trust and not about money: an AI billed
                 per investigation costs most during an outage, which is when it fires
                 most, which is precisely when a surprise invoice destroys the trust the
                 rest of this section is building. --}}
            <p data-reveal style="--reveal-index: 3" class="mt-12 border-t border-border pt-8 text-body-lg text-fg-muted">
                {{ __('And it is included in the plan rather than billed per investigation. Metered AI sends its largest invoice on your worst day, and a feature you hesitate to let run is not a feature.') }}
            </p>
        </div>
    </section>
@endif
