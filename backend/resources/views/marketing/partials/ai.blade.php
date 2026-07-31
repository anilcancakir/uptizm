{{--
    The AI section, rendered only when the deployment actually has an AI provider
    key (see ShowLandingController::aiEnabled). Without one, every AI path in the
    product falls back to its deterministic baseline, and a page advertising
    "AI-assisted triage" against that would be selling the fallback.

    It sits this far down the page on purpose. In this category, leading with AI
    now reads as a substitute for a product; the copy below therefore names the
    mechanism and where the output lands instead of using "AI-powered" as an
    adjective.
--}}
<section id="ai" class="border-b border-border bg-ai-wash">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
        <div class="max-w-2xl">
            @include('marketing.partials.eyebrow', ['text' => __('Assisted, not autonomous'), 'tone' => 'ai'])
            <h2 data-reveal class="mt-3 text-section text-balance text-fg">{{ __('AI that has to') }} <span class="text-ai">{{ __('show its evidence') }}</span></h2>
            <p class="mt-4 text-body-lg text-fg-muted">
                {{ __('It drafts, cites and waits. Every suggestion arrives with the signals it was drawn from, and a person accepts or dismisses it.') }}
            </p>
        </div>

        <div class="mt-12 grid gap-6 lg:grid-cols-[1.1fr_1fr] lg:gap-10">
            <div class="grid gap-6 sm:grid-cols-2">
                @foreach ([
                    [
                        'title' => __('Anomaly triage'),
                        'body' => __('Statistical outliers in your check history become a drafted suggestion in an inbox: what looks wrong, how confident it is, and which samples say so.'),
                    ],
                    [
                        'title' => __('Incident analysis'),
                        'body' => __('On an open incident, a probable cause drafted from the checks, the timings and the recent history of the monitors involved.'),
                    ],
                    [
                        'title' => __('Ask about your own systems'),
                        'body' => __('A question box that answers from your monitors, checks and incidents rather than from the internet.'),
                    ],
                    [
                        'title' => __('Weekly digest'),
                        'body' => __('A written summary of the week: what broke, what recovered, and what is trending the wrong way.'),
                    ],
                ] as $feature)
                    <article data-reveal class="rounded-lg border border-border bg-surface-container p-6 transition-colors hover:border-fg-disabled">
                        <h3 class="text-title-lg text-fg">{{ $feature['title'] }}</h3>
                        <p class="mt-2 text-body-md text-fg-muted">{{ $feature['body'] }}</p>
                    </article>
                @endforeach
            </div>

            <div data-reveal class="rounded-lg border border-ai/25 bg-surface-container p-6">
                <h3 class="text-title-lg text-fg">{{ __('The guardrails, specifically') }}</h3>

                <ul class="mt-5 space-y-4">
                    @foreach ([
                        __('It may only cite signals from your own account. A citation pointing anywhere else is dropped rather than shown.'),
                        __('Your check data reaches the model fenced as untrusted input, so text in a response body cannot become an instruction.'),
                        __('Out of budget, unavailable, or an answer that does not fit the required shape: you get the deterministic analysis instead, and it says which one you are looking at.'),
                        __('There is a per-team daily spend cap, so a noisy week cannot become a surprise bill.'),
                    ] as $guardrail)
                        <li class="flex gap-3 text-body-md text-fg-muted">
                            <span class="mt-2 size-1.5 shrink-0 rounded-full bg-ai"></span>
                            <span>{{ $guardrail }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>
