{{--
    Recent public incidents, grouped by the day they started. Each entry shows
    its title, a lifecycle badge, when it started, its public updates NEWEST
    FIRST, and a published postmortem when one exists (internal-only updates and
    UNPUBLISHED postmortems never reach this view, the assembler filters both
    out).

    Each update prints its own status label and an absolute UTC timestamp, which
    is the shape a reader already knows from every other status page and the only
    shape that survives being read a day later or quoted in a ticket.
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
    /*
     * The per-update status label, the "Resolved - ", "Investigating - " prefix
     * every status page a visitor has already read puts in front of the message.
     *
     * The status was in the read model from the start and the template simply
     * never printed it, so the history read as a stack of anonymous sentences: a
     * visitor could not tell which line was the resolution and which was the
     * first acknowledgement without inferring it from the prose.
     */
    $updateStatusLabel = static fn (string $status): string => match ($status) {
        'detected',
        'investigating',
        'identified',
        'monitoring',
        'mitigated',
        'resolved' => (string) __('status.incidents.update_status.'.$status),
        // A state the catalogue has no entry for still has to read as a word
        // rather than as a snake_case key. It stays untranslated by necessity:
        // there is nothing to look up.
        default => ucfirst(str_replace('_', ' ', $status)),
    };

    /*
     * The badge's own text. It was `str_replace('_', ' ', $lifecycle)`, which is a
     * HUMANISATION of the stored value rather than a machine token, so it is copy
     * and it belongs in the catalogue. The English entries reproduce that
     * humanised form exactly, so the English page renders byte for byte what it
     * rendered before; a lifecycle the catalogue has no entry for falls back to
     * the old expression.
     */
    $lifecycleBadgeLabel = static fn (string $lifecycle): string => match ($lifecycle) {
        'detected',
        'investigating',
        'identified',
        'monitoring',
        'mitigated',
        'resolved' => (string) __('status.incidents.lifecycle_badge.'.$lifecycle),
        default => str_replace('_', ' ', $lifecycle),
    };

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
    <h2 class="border-b border-border px-5 py-3 text-sm font-semibold text-fg-muted uppercase">{{ __('status.incidents.heading') }}</h2>

    @if ($vm->incidents === [])
        <p class="px-5 py-6 text-sm text-fg-muted">{{ __('status.incidents.empty') }}</p>
    @else
        <div class="divide-y divide-border-subtle">
            @foreach ($vm->incidents as $group)
                <div class="px-5 py-4">
                    {{-- `translatedFormat` with a format string out of the catalogue,
                         because both the month NAME and its position are part of the
                         language. Every other date on this page sits inside a `<time>`
                         element that the layout's script rewrites to the viewer's own
                         locale; this heading is the one that does not, so it is the one
                         that has to carry its own. --}}
                    <h3 class="mb-3 text-sm font-semibold text-fg-muted">
                        {{ \Illuminate\Support\Carbon::parse($group['day'])->locale(app()->getLocale())->translatedFormat(__('status.incidents.day_format')) }}
                    </h3>

                    <ul class="space-y-4">
                        @foreach ($group['entries'] as $entry)
                            <li>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ $entry['title'] }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $lifecycleBadgeClass($entry['lifecycle']) }}">
                                        {{ $lifecycleBadgeLabel($entry['lifecycle']) }}
                                    </span>
                                    {{-- The stamp travels as a PARAMETER rather than as text
                                         beside a translated word, because Turkish puts the
                                         verb after the time and English before it. --}}
                                    <time datetime="{{ $entry['startedAt'] }}" class="text-xs text-fg-muted">
                                        {{-- `translatedFormat` with the page's locale, like the day
                                             heading above: a bare `format()` puts an English month
                                             abbreviation inside a Turkish sentence. Only a reader
                                             without JavaScript ever sees this text, because the
                                             layout's script replaces the whole `<time>` content with
                                             the viewer's own localized stamp, but that reader (and a
                                             crawler) should not be handed half a language. --}}
                                        {{ __('status.incidents.started', ['at' => \Illuminate\Support\Carbon::parse($entry['startedAt'])->utc()->locale(app()->getLocale())->translatedFormat('M j, H:i').' UTC']) }}
                                    </time>
                                </div>

                                @if ($entry['updates'] !== [])
                                    <ul class="mt-2 space-y-2 border-l border-border pl-4">
                                        @foreach ($entry['updates'] as $update)
                                            <li class="text-sm">
                                                <p class="text-fg">
                                                    <span class="font-semibold">{{ $updateStatusLabel($update['status']) }}</span>
                                                    <span class="text-fg-muted">&ndash;</span>
                                                    {{ $update['message'] }}
                                                </p>

                                                {{-- ABSOLUTE, and in UTC. "15 minutes ago" is
                                                     useless in a history: it is unreadable a day
                                                     later, it differs on every reload, and it
                                                     cannot be quoted in a ticket. UTC because a
                                                     status page is read from every timezone and
                                                     the server's own is nobody's answer.

                                                     The actor is printed only when it is NOT a
                                                     human: "human" is internal vocabulary that
                                                     tells a customer nothing, while an AI-written
                                                     update is something they are owed. --}}
                                                <p class="text-xs text-fg-muted">
                                                    <time datetime="{{ $update['displayAt'] }}">
                                                        {{ \Illuminate\Support\Carbon::parse($update['displayAt'])->utc()->format('M j, H:i') }} UTC
                                                    </time>

                                                    @if ($update['actor'] !== 'human')
                                                        &middot; {{ $update['actor'] }}
                                                    @endif
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
                                        <h4 class="text-xs font-semibold text-fg-muted uppercase">{{ __('status.incidents.postmortem') }}</h4>
                                        <p class="mt-1 text-sm whitespace-pre-line text-fg">{{ $entry['postmortem']['body'] }}</p>
                                        {{-- A prefix / suffix pair around the `<time>`, not one
                                             sentence with a placeholder: the element has to stay
                                             (the layout's script rewrites it to the viewer's own
                                             zone) and emitting it through a translation
                                             parameter would need an UNESCAPED echo, which no
                                             status view may carry. English puts the verb before
                                             the stamp and Turkish after it, so each locale
                                             leaves one half of the pair empty. --}}
                                        <p class="mt-1 text-xs text-fg-muted">
                                            {{ __('status.incidents.postmortem_published_before') }}
                                            <time datetime="{{ $entry['postmortem']['publishedAt'] }}">
                                                {{ \Illuminate\Support\Carbon::parse($entry['postmortem']['publishedAt'])->locale(app()->getLocale())->diffForHumans() }}
                                            </time>
                                            {{ __('status.incidents.postmortem_published_after') }}
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
