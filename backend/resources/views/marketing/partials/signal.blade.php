{{--
    The differentiating section, and the one that has to be exactly true: four
    rules about what Uptizm does when it is UNSURE. Every one of them is a
    behaviour in the codebase, not an intention:

      - the refused-probe branch in CheckPersistenceService::persist()
      - the trigger_metric_key scoping in ThresholdEvaluator::resolveIfRecovered()
      - the probe_run_id idempotency guard in CheckPersistenceService
      - the nullable uptime + nullable day-segment rendering

    If one of those changes, this copy is wrong and has to change with it.
--}}
<section id="signal" class="border-b border-border bg-surface-container">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
        <div class="max-w-2xl">
            <p class="text-label-sm uppercase tracking-[0.14em] text-fg-muted">{{ __('Signal, not noise') }}</p>
            <h2 class="mt-3 text-section text-balance text-fg">{{ __('What it does when it does not know') }}</h2>
            <p class="mt-4 text-body-lg text-fg-muted">
                {{ __('Any monitor can tell you a site is down. The harder question is what it reports when the answer is genuinely unclear, because that is where false pages and false confidence both come from.') }}
            </p>
        </div>

        <div class="mt-12 grid gap-6 md:grid-cols-2">
            @foreach ([
                [
                    'title' => __('A check we could not run is not an outage'),
                    'body' => __('If the edge itself refuses to execute a probe, that measured nothing about your service. It is recorded as a configuration problem on the monitor, and nobody is paged. It does not count as a success either, because clearing the failure streak would hide a real outage underneath.'),
                ],
                [
                    'title' => __('A recovery only closes what it fixed'),
                    'body' => __('A healthy response resolves the "is it down" incident. It does not touch an incident opened by an expiring TLS certificate or a breached latency bound, because a reachable endpoint does not renew a certificate or make itself fast again.'),
                ],
                [
                    'title' => __('The same result twice changes nothing'),
                    'body' => __('Delivery is at-least-once, so a probe result can arrive more than once. Each carries an id: a repeat writes no second row, advances no counter and opens no second incident.'),
                ],
                [
                    'title' => __('No data is a state, not a zero'),
                    'body' => __('A monitor with no checks yet shows no uptime figure, not 100% and not 0%. A day it was not being watched renders neutral on the history bar instead of green. An empty status page says so rather than claiming all systems are operational.'),
                ],
            ] as $rule)
                <article class="rounded-lg border border-border bg-surface p-6">
                    <h3 class="flex gap-3 text-title-lg text-fg">
                        <svg class="mt-0.5 size-5 shrink-0 text-primary" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 10.5l2 2 4-5" />
                            <circle cx="10" cy="10" r="7.25" />
                        </svg>
                        <span>{{ $rule['title'] }}</span>
                    </h3>
                    <p class="mt-3 pl-8 text-body-md text-fg-muted">{{ $rule['body'] }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>
