{{--
    Spotlight on response-metric extraction, the one capability here that most of
    this category does not have at all: competitors monitor whether a URL answers
    and how fast. This monitors what the answer SAID.

    The panel is an example request against a placeholder endpoint. The five
    extraction sources named in the copy are the five MetricExtractor implements.
--}}
<section class="border-b border-border bg-surface-container">
    <div class="mx-auto grid max-w-6xl gap-12 px-4 py-16 sm:px-6 sm:py-20 lg:grid-cols-2 lg:items-center lg:gap-16 lg:px-8">
        <div>
            <p class="text-label-sm uppercase tracking-[0.14em] text-fg-muted">{{ __('Beyond up and down') }}</p>
            <h2 class="mt-3 text-section text-balance text-fg">{{ __('Your health endpoint already knows. Read it.') }}</h2>

            <p class="mt-4 text-body-lg text-fg-muted">
                {{ __('A queue that is 40,000 jobs deep answers HTTP 200 in 30 milliseconds. Uptime monitoring calls that healthy. Uptizm reads the number out of the response and treats it as a signal in its own right.') }}
            </p>

            <ul class="mt-8 space-y-3">
                @foreach ([
                    __('Extract with JSONPath, XPath, a regex, a response header, or the status code itself.'),
                    __('Give each metric a warning and a critical bound; a breach opens an incident with the sample attached.'),
                    __('Chart the series over time next to the response times for the same monitor.'),
                ] as $point)
                    <li class="flex gap-3 text-body-md text-fg-muted">
                        <svg class="mt-1 size-4 shrink-0 text-primary" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 8.5l3 3 6-7" />
                        </svg>
                        <span>{{ $point }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div
            role="img"
            aria-label="{{ __('Example: a metric extracted from a health endpoint breaches its warning bound and opens an incident.') }}"
            class="overflow-hidden rounded-lg border border-border bg-surface font-mono text-label-sm"
        >
            <div class="flex items-center justify-between gap-3 border-b border-border-subtle px-5 py-3.5">
                <span class="text-fg">GET /health</span>
                <span class="text-fg-muted tabular-nums">200 · 42 ms</span>
            </div>

            <pre class="overflow-x-auto px-5 py-4 leading-relaxed text-fg-muted"><span class="text-fg-muted">{</span>
  <span class="text-fg">"queue"</span>: { <span class="text-fg">"pending"</span>: <span class="rounded-sm bg-degraded-soft px-1 text-degraded-soft-foreground">1843</span> },
  <span class="text-fg">"workers"</span>: 4,
  <span class="text-fg">"version"</span>: <span class="text-fg">"2.4.1"</span>
<span class="text-fg-muted">}</span></pre>

            <div class="border-t border-border-subtle px-5 py-4">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="text-fg">queue_depth</span>
                    <span class="text-fg-muted">$.queue.pending</span>
                </div>
                <div class="mt-1.5 flex flex-wrap gap-x-5 gap-y-1 text-fg-muted tabular-nums">
                    <span>{{ __('warn') }} &ge; 1000</span>
                    <span>{{ __('critical') }} &ge; 5000</span>
                </div>
            </div>

            <div class="flex items-start gap-2.5 border-t border-border-subtle bg-degraded-soft px-5 py-4 text-degraded-soft-foreground">
                <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-degraded"></span>
                <span>{{ __('1843 crossed the warning bound: incident opened, severity warning') }}</span>
            </div>
        </div>
    </div>
</section>
