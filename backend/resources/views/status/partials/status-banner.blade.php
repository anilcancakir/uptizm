{{--
    Overall status banner: a colored dot on the severity ladder
    (operational < degraded < partial_outage < major_outage), the human
    label, and a "last updated" timestamp.

    `$vm->overallLabel` arrives ALREADY LOCALIZED from
    `StatusPageAssembler::overallLabel()`, which resolves `status.banner.*` under
    the locale the controller applied from `status_pages.locale`. It is not an
    English string this template has to translate.
--}}
@php
    // One map, in App\Support\StatusPages\StatusPresentation, so this dot and the
    // favicon in the browser tab can never end up different colours.
    $bannerDotClass = \App\Support\StatusPages\StatusPresentation::dotClass($vm->overallStatus);

    // The snapshot's assembly time (carried in the cached DTO), so the label
    // reflects the age of the data shown, not the moment the page rendered.
    //
    // `locale()` is set explicitly because nothing syncs Carbon's locale to the
    // application's: `Application::setLocale()` updates the config and the
    // translator and dispatches `LocaleUpdated`, and no listener in this app or
    // the framework forwards it to Carbon. Without this the Turkish page read
    // "3 minutes ago güncellendi".
    $updatedAt = \Illuminate\Support\Carbon::parse($vm->generatedAt)->locale(app()->getLocale());
@endphp

<section class="mb-6 flex items-center justify-between rounded-lg border border-border bg-surface-container px-5 py-4">
    <div class="flex items-center gap-3">
        <span class="h-3 w-3 shrink-0 rounded-full {{ $bannerDotClass }}"></span>
        <span class="text-lg font-semibold">{{ $vm->overallLabel }}</span>
    </div>

    <time datetime="{{ $vm->generatedAt }}" class="text-sm text-fg-muted">
        {{ __('status.banner.updated', ['ago' => $updatedAt->diffForHumans()]) }}
    </time>
</section>
