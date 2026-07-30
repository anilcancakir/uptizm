{{--
    Overall status banner: a colored dot on the severity ladder
    (operational < degraded < partial_outage < major_outage), the human
    label, and a "last updated" timestamp.
--}}
@php
    // One map, in App\Support\StatusPages\StatusPresentation, so this dot and the
    // favicon in the browser tab can never end up different colours.
    $bannerDotClass = \App\Support\StatusPages\StatusPresentation::dotClass($vm->overallStatus);

    // The snapshot's assembly time (carried in the cached DTO), so the label
    // reflects the age of the data shown, not the moment the page rendered.
    $updatedAt = \Illuminate\Support\Carbon::parse($vm->generatedAt);
@endphp

<section class="mb-6 flex items-center justify-between rounded-lg border border-gray-200 bg-white px-5 py-4">
    <div class="flex items-center gap-3">
        <span class="h-3 w-3 shrink-0 rounded-full {{ $bannerDotClass }}"></span>
        <span class="text-lg font-semibold">{{ $vm->overallLabel }}</span>
    </div>

    <time datetime="{{ $vm->generatedAt }}" class="text-sm text-gray-500">
        updated {{ $updatedAt->diffForHumans() }}
    </time>
</section>
