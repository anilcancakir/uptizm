{{--
    Component rows: label, status dot, a 90-day uptime bar (one cell per day,
    colored on the severity ladder), and the rolled-up uptime percent.
--}}
@php
    $componentDotClass = static fn (string $status): string => match ($status) {
        'major_outage' => 'bg-red-500',
        'partial_outage' => 'bg-orange-500',
        'degraded' => 'bg-amber-500',
        default => 'bg-green-500',
    };
@endphp

<section class="mb-6 rounded-lg border border-gray-200 bg-white">
    <h2 class="border-b border-gray-200 px-5 py-3 text-sm font-semibold text-gray-500 uppercase">Components</h2>

    @if ($vm->components === [])
        <p class="px-5 py-6 text-sm text-gray-500">No components are currently published on this page.</p>
    @else
        <ul>
            @foreach ($vm->components as $component)
                <li class="flex flex-col gap-2 border-b border-gray-100 px-5 py-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $componentDotClass($component['status']) }}"></span>
                        <span class="font-medium">{{ $component['label'] }}</span>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="flex gap-px" aria-hidden="true">
                            @foreach ($component['strip'] as $day)
                                <span
                                    class="h-4 w-1 rounded-sm {{ $componentDotClass($day['status']) }}"
                                    title="{{ $day['date'] }}: {{ $day['status'] }}"
                                ></span>
                            @endforeach
                        </div>

                        <span class="text-sm text-gray-500 tabular-nums">{{ number_format($component['uptimePercent'], 2) }}%</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
