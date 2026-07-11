{{--
    Recent public incidents, grouped by the day they started. Each entry shows
    its title, a lifecycle badge, when it started, and its public updates
    (internal-only updates never reach this view, they are filtered out by
    the assembler).
--}}
@php
    $lifecycleBadgeClass = static fn (string $lifecycle): string => match ($lifecycle) {
        'resolved' => 'bg-green-100 text-green-800',
        'monitoring', 'mitigated' => 'bg-blue-100 text-blue-800',
        'identified' => 'bg-orange-100 text-orange-800',
        'investigating' => 'bg-amber-100 text-amber-800',
        default => 'bg-gray-100 text-gray-800',
    };
@endphp

<section class="mb-6 rounded-lg border border-gray-200 bg-white">
    <h2 class="border-b border-gray-200 px-5 py-3 text-sm font-semibold text-gray-500 uppercase">Incidents</h2>

    @if ($vm->incidents === [])
        <p class="px-5 py-6 text-sm text-gray-500">No incidents reported.</p>
    @else
        <div class="divide-y divide-gray-100">
            @foreach ($vm->incidents as $group)
                <div class="px-5 py-4">
                    <h3 class="mb-3 text-sm font-semibold text-gray-500">
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
                                    <time datetime="{{ $entry['startedAt'] }}" class="text-xs text-gray-500">
                                        started {{ \Illuminate\Support\Carbon::parse($entry['startedAt'])->diffForHumans() }}
                                    </time>
                                </div>

                                @if ($entry['updates'] !== [])
                                    <ul class="mt-2 space-y-2 border-l border-gray-200 pl-4">
                                        @foreach ($entry['updates'] as $update)
                                            <li class="text-sm">
                                                <p class="text-gray-700">{{ $update['message'] }}</p>
                                                <p class="text-xs text-gray-500">
                                                    {{ $update['actor'] }} &middot;
                                                    <time datetime="{{ $update['displayAt'] }}">
                                                        {{ \Illuminate\Support\Carbon::parse($update['displayAt'])->diffForHumans() }}
                                                    </time>
                                                </p>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
</section>
