{{--
    Recent public incidents, grouped by the day they started. Each entry shows
    its title, a lifecycle badge, when it started, its public updates, and a
    published postmortem when one exists (internal-only updates and UNPUBLISHED
    postmortems never reach this view, the assembler filters both out).
--}}
@php
    /*
     * The lifecycle badge, on the status vocabulary's soft tones: `-soft` for the
     * background and `-soft-foreground` for the text, which is the pair those two
     * tones exist for.
     *
     * The mapping keeps the reading each state already had, and the two judgement
     * calls are these. `identified` was orange, and there is no orange family: of
     * the two it could collapse into it takes `down`, because an identified incident
     * is one whose cause is known and whose impact is still live, which reads with
     * the urgency of an outage rather than of slowness. And `detected` stays
     * NEUTRAL: it means the alert fired and no human has reached it yet, so the page
     * has nothing to characterise beyond "we know". Anything unrecognised lands
     * there too, one step from green rather than in it.
     */
    $lifecycleBadgeClass = static fn (string $lifecycle): string => match ($lifecycle) {
        'resolved' => 'bg-up-soft text-up-soft-foreground',
        'monitoring', 'mitigated' => 'bg-info-soft text-info-soft-foreground',
        'identified' => 'bg-down-soft text-down-soft-foreground',
        'investigating' => 'bg-degraded-soft text-degraded-soft-foreground',
        'detected' => 'bg-paused-soft text-paused-soft-foreground',
        default => 'bg-paused-soft text-paused-soft-foreground',
    };
@endphp

<section class="mb-6 rounded-lg border border-border bg-surface-container">
    <h2 class="border-b border-border px-5 py-3 text-sm font-semibold text-fg-muted uppercase">Incidents</h2>

    @if ($vm->incidents === [])
        <p class="px-5 py-6 text-sm text-fg-muted">No incidents reported.</p>
    @else
        <div class="divide-y divide-border-subtle">
            @foreach ($vm->incidents as $group)
                <div class="px-5 py-4">
                    <h3 class="mb-3 text-sm font-semibold text-fg-muted">
                        {{ \Illuminate\Support\Carbon::parse($group['day'])->format('F j, Y') }}
                    </h3>

                    <ul class="space-y-4">
                        @foreach ($group['entries'] as $entry)
                            <li>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ $entry['title'] }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $lifecycleBadgeClass($entry['lifecycle']) }}">
                                        {{ str_replace('_', ' ', $entry['lifecycle']) }}
                                    </span>
                                    <time datetime="{{ $entry['startedAt'] }}" class="text-xs text-fg-muted">
                                        started {{ \Illuminate\Support\Carbon::parse($entry['startedAt'])->diffForHumans() }}
                                    </time>
                                </div>

                                @if ($entry['updates'] !== [])
                                    <ul class="mt-2 space-y-2 border-l border-border pl-4">
                                        @foreach ($entry['updates'] as $update)
                                            <li class="text-sm">
                                                <p class="text-fg">{{ $update['message'] }}</p>
                                                <p class="text-xs text-fg-muted">
                                                    {{ $update['actor'] }} &middot;
                                                    <time datetime="{{ $update['displayAt'] }}">
                                                        {{ \Illuminate\Support\Carbon::parse($update['displayAt'])->diffForHumans() }}
                                                    </time>
                                                </p>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if (($entry['postmortem'] ?? null) !== null)
                                    {{-- A panel nested inside the card, so it steps UP the surface
                                         hierarchy (surface-container -> -high) rather than reaching for
                                         a shadow. DESIGN.md expresses depth tonally. --}}
                                    <div class="mt-3 rounded-md bg-surface-container-high px-4 py-3">
                                        <h4 class="text-xs font-semibold text-fg-muted uppercase">Postmortem</h4>
                                        <p class="mt-1 text-sm whitespace-pre-line text-fg">{{ $entry['postmortem']['body'] }}</p>
                                        <p class="mt-1 text-xs text-fg-muted">
                                            published
                                            <time datetime="{{ $entry['postmortem']['publishedAt'] }}">
                                                {{ \Illuminate\Support\Carbon::parse($entry['postmortem']['publishedAt'])->diffForHumans() }}
                                            </time>
                                        </p>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
</section>
