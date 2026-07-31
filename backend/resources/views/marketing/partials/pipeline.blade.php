{{--
    The mechanism, in the order it happens. This section exists because the
    category's credible pages lead with how the thing works rather than with an
    adjective, and because every claim below is one a reader could verify by
    watching a single check run.
--}}
<section id="pipeline" class="border-b border-border">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
        <div class="max-w-2xl">
            <h2 class="text-section text-balance text-fg">{{ __('How a check becomes a page') }}</h2>
            <p class="mt-4 text-body-lg text-fg-muted">
                {{ __('Four steps, and a decision in the middle of them. Most alert fatigue comes from tools that skip step three.') }}
            </p>
        </div>

        <ol class="mt-12 grid gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                [
                    'label' => __('Probe'),
                    'title' => __('Runs where your users are'),
                    'body' => __('A signed check spec goes to a worker pinned to the region you picked. It issues the request from that geography and reports back the status, the timing and the data centre it ran in.'),
                ],
                [
                    'label' => __('Record'),
                    'title' => __('Stores the evidence, per region'),
                    'body' => __('Each result is written against the region it was requested for and the data centre it actually came from, so "checked from EU West" is something you can audit rather than a label we printed.'),
                ],
                [
                    'label' => __('Decide'),
                    'title' => __('One bad check is not an incident'),
                    'body' => __('A failure advances a counter. Nothing opens until it crosses the monitor\'s threshold, which defaults to two in a row and is yours to set. A breached metric bound can open one too.'),
                ],
                [
                    'label' => __('Page'),
                    'title' => __('Walks your escalation policy'),
                    'body' => __('The incident goes step by step to whoever is on call, over the destinations you configured. Each one is throttled, so a flapping monitor cannot turn into forty messages.'),
                ],
            ] as $index => $step)
                <li class="bg-surface-container p-6">
                    <div class="flex items-center gap-3">
                        <span class="flex size-7 items-center justify-center rounded-full bg-primary-container font-mono text-label-sm text-primary">
                            {{ $index + 1 }}
                        </span>
                        <span class="text-label-sm uppercase tracking-[0.14em] text-fg-muted">{{ $step['label'] }}</span>
                    </div>

                    <h3 class="mt-4 text-title-lg text-fg">{{ $step['title'] }}</h3>
                    <p class="mt-2 text-body-md text-fg-muted">{{ $step['body'] }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>
