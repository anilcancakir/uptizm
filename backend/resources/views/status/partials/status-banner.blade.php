{{--
    Overall status banner: a colored dot on the severity ladder
    (operational < degraded < partial_outage < major_outage), the human
    label, and a "last updated" timestamp.
--}}
@php
    $bannerDotClass = match ($vm->overallStatus) {
        'major_outage' => 'bg-red-500',
        'partial_outage' => 'bg-orange-500',
        'degraded' => 'bg-amber-500',
        default => 'bg-green-500',
    };

    $updatedAt = now();
@endphp

<section class="mb-6 flex items-center justify-between rounded-lg border border-gray-200 bg-white px-5 py-4">
    <div class="flex items-center gap-3">
        <span class="h-3 w-3 shrink-0 rounded-full {{ $bannerDotClass }}"></span>
        <span class="text-lg font-semibold">{{ $vm->overallLabel }}</span>
    </div>

    <time datetime="{{ $updatedAt->toIso8601String() }}" class="text-sm text-gray-500">
        updated {{ $updatedAt->diffForHumans() }}
    </time>
</section>
