{{--
    Act 4: it reaches a person, and keeps going until one of them answers.

    This replaced a platform matrix (Web / iOS / Android with live and soon badges),
    which was wrong twice over. It conflated "the alert arrived" with "which platforms
    you can install", so it read as though the alert had NOT reached iOS or Android,
    undercutting the very claim it was making. And delivery is not a platform question
    at all: it is a question of which channel, to which person, and what happens when
    nobody answers. The platform claim lives in the hero's pill, where it is a fact
    about the client rather than a fact about this incident.

    Channel names come from NotificationChannelType via the controller, so the ladder
    cannot list a destination the product cannot deliver to.

    The terminal beat is RESOLUTION, not acknowledgement, and that correction matters.
    It read "Acknowledged, paging stops", which the code does not do:
    `EscalationDispatcher::pageStep()` short-circuits on `! lifecycle->isActive()` and
    `isTerminal()` is `Resolved` alone, while acknowledging moves an incident from
    Detected to Investigating, which is still active. So acknowledging leaves the ladder
    climbing and only a resolution cancels the steps still pending. Advertising the
    opposite would have promised the one thing an operator most needs to be true.
--}}
<div data-act x-show="act === 4" x-cloak class="absolute inset-0 flex flex-col p-5">
    <div class="flex items-baseline justify-between">
        <p class="text-label-sm uppercase tracking-[0.12em] text-fg-muted">{{ __('Escalation') }}</p>
        <p class="font-mono text-label-sm text-fg-muted" x-text="resolvedAt ? '{{ __('resolved') }}' : '{{ __('paging') }}'"></p>
    </div>

    {{-- Split by STEP, with the waiting beat between the two groups. A single loop
         with the divider appended after it put "no acknowledgement" below every row,
         which reads as though step 2 fired before anyone failed to answer. --}}
    <ul class="mt-3 space-y-1.5">
        <template x-for="row in escalation.filter((r) => r.step === 1)" :key="row.channel">
            <li class="flex items-center gap-3 rounded-base border border-border-subtle bg-surface px-3 py-2">
                <span class="w-10 shrink-0 font-mono text-label-sm text-fg-muted" x-text="row.at"></span>
                <span class="w-28 shrink-0 truncate text-body-md text-fg" x-text="row.channel"></span>
                <span class="min-w-0 flex-1 truncate font-mono text-label-sm text-fg-muted" x-text="row.target"></span>
                <span class="shrink-0 text-label-sm" style="color: var(--app-up)">{{ __('sent') }}</span>
            </li>
        </template>

        {{-- The waiting beat. This is the whole reason escalation exists, so it gets a
             line of its own rather than being implied by a gap. --}}
        <li x-show="waiting" x-cloak class="flex items-center gap-3 px-3 py-1">
            <span class="h-px flex-1 bg-border"></span>
            <span class="shrink-0 text-label-sm text-fg-muted">{{ __('still open, so step 2 fires') }}</span>
            <span class="h-px flex-1 bg-border"></span>
        </li>

        <template x-for="row in escalation.filter((r) => r.step === 2)" :key="row.channel">
            <li class="flex items-center gap-3 rounded-base border border-border-subtle bg-surface px-3 py-2">
                <span class="w-10 shrink-0 font-mono text-label-sm text-fg-muted" x-text="row.at"></span>
                <span class="w-28 shrink-0 truncate text-body-md text-fg" x-text="row.channel"></span>
                <span class="min-w-0 flex-1 truncate font-mono text-label-sm text-fg-muted" x-text="row.target"></span>
                <span class="shrink-0 text-label-sm" style="color: var(--app-up)">{{ __('sent') }}</span>
            </li>
        </template>
    </ul>

    <div
        x-show="resolvedAt"
        x-cloak
        class="mt-auto flex items-center gap-3 rounded-base px-3 py-2.5"
        style="background-color: var(--app-up-soft)"
    >
        <span class="size-2 shrink-0 rounded-full" style="background-color: var(--app-up)"></span>
        <span class="text-label-md" style="color: var(--app-up-soft-foreground)">{{ __('Resolved') }}</span>
        <span class="font-mono text-label-sm" style="color: var(--app-up-soft-foreground)" x-text="resolvedAt"></span>
        <span class="ml-auto text-label-sm" style="color: var(--app-up-soft-foreground)">{{ __('pending pages cancelled') }}</span>
    </div>

    <p x-show="! resolvedAt" x-cloak class="mt-auto text-label-sm text-fg-muted">
        {{ __('Each destination is throttled, so one flapping monitor cannot become forty messages.') }}
    </p>
</div>
