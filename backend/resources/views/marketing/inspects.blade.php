{{--
    Section 3: what one check inspects.

    This is the depth argument, and it exists because the breadth argument is one we
    lose. The tools in this category enumerate check types as a trust signal (Better
    Stack and Oh Dear both list out SSL, domain expiry, POP3/IMAP/SMTP, Lighthouse,
    broken links) and uptizm has two: HTTP and TCP. Competing on that list is competing
    on our weakest axis.

    So the section answers a different question: not how many things it can check, but
    how much of one response it actually reads. That is genuinely uncommon at this price,
    and unlike a protocol list it is something a reader can act on immediately, because
    it is about a number their own endpoint already returns.

    The sample is an illustration, and it is deliberately the same number the hero's
    third act shows (queue depth 4812, bound 1000) so the two read as one story rather
    than two unrelated examples.
--}}
<section id="beyond-status-codes" class="border-b border-border">
    <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
        <div class="grid gap-14 lg:grid-cols-2 lg:items-center lg:gap-16">
            <div data-reveal>
                <p class="text-label-md text-accent">{{ __('What it inspects') }}</p>

                <h2 class="mt-3 text-section text-balance text-fg">
                    {{ __('A 200 only means') }}
                    <span class="text-primary">{{ __('something answered') }}</span>.
                </h2>

                <p class="mt-5 max-w-xl text-body-lg text-fg-muted">
                    {{ __('Your queue can be forty thousand deep, your certificate can have nine days left, and your endpoint will still cheerfully return 200. So a check here reads more than the code it got back.') }}
                </p>

                <dl class="mt-10 flex flex-col gap-8">
                    @foreach ($inspections as $item)
                        <div data-reveal style="--reveal-index: {{ $loop->index + 1 }}" class="border-l-2 border-border pl-5">
                            <dt class="text-title-lg text-fg">{{ $item['title'] }}</dt>
                            <dd class="mt-2 text-body-md text-fg-muted">{{ $item['body'] }}</dd>
                            {{-- Same device as the rules section: the real identifier,
                                 untranslated, so it stays something you can go and check. --}}
                            <dd class="mt-3">
                                <code class="rounded-base bg-surface-container-high px-2 py-1 font-mono text-label-sm text-fg-muted">{{ $item['mechanism'] }}</code>
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- The sample. A response, a selector, a bound, and the verdict that follows,
                 in the order you would actually build it. --}}
            <figure data-reveal style="--reveal-index: 1" class="overflow-hidden rounded-lg border border-border bg-surface-container">
                <figcaption class="flex items-center justify-between border-b border-border-subtle px-5 py-3">
                    <span class="font-mono text-label-sm text-fg-muted">GET /health</span>
                    <span class="text-label-sm text-fg-disabled">{{ __('Example') }}</span>
                </figcaption>

                <div class="flex flex-col gap-5 p-5">
                    <div data-reveal style="--reveal-index: 2">
                        <p class="text-micro uppercase tracking-[0.12em] text-fg-muted">{{ __('What came back') }}</p>
                        <pre class="mt-2 overflow-x-auto rounded-base bg-surface p-4 font-mono text-label-sm text-fg"><code>{
  "status": "ok",
  "queue": { "depth": <span class="text-degraded">4812</span>, "workers": 8 },
  "build": "2.31.0"
}</code></pre>
                    </div>

                    <div data-reveal style="--reveal-index: 3" class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-base border border-border bg-surface px-4 py-3">
                            <p class="text-micro uppercase tracking-[0.12em] text-fg-muted">{{ __('Selector') }}</p>
                            <p class="mt-1 font-mono text-body-md text-fg">$.queue.depth</p>
                        </div>
                        <div class="rounded-base border border-border bg-surface px-4 py-3">
                            <p class="text-micro uppercase tracking-[0.12em] text-fg-muted">{{ __('Bound') }}</p>
                            <p class="mt-1 font-mono text-body-md text-fg">warn &ge; 1000</p>
                        </div>
                    </div>

                    {{-- The verdict. Uses the degraded family rather than down, because a
                         breached bound on a responding endpoint is exactly that: the thing
                         answered, and it is not well. --}}
                    <div data-reveal style="--reveal-index: 5; background-color: var(--app-degraded-soft)" class="flex items-center gap-3 rounded-base px-4 py-3">
                        <span class="size-2 shrink-0 rounded-full" style="background-color: var(--app-degraded)"></span>
                        <span class="text-label-md" style="color: var(--app-degraded-soft-foreground)">{{ __('Degraded') }}</span>
                        <span class="text-label-sm" style="color: var(--app-degraded-soft-foreground)">{{ __('bound crossed, HTTP 200 throughout') }}</span>
                    </div>
                </div>
            </figure>
        </div>
    </div>
</section>
