{{-- Act 1.5: the transition. One spec goes out to every region at the same instant,
     which is the claim the next act then evidences. --}}
<div data-act x-show="act === 1.5" x-cloak class="absolute inset-0 flex flex-col items-center justify-center gap-4">
    <div class="relative flex size-24 items-center justify-center">
        <span data-pulse class="absolute size-24 rounded-full bg-primary" aria-hidden="true"></span>
        <span data-pulse style="animation-delay: .5s" class="absolute size-24 rounded-full bg-primary" aria-hidden="true"></span>
        <span class="relative size-3 rounded-full bg-primary"></span>
    </div>

    <p class="font-mono text-label-sm text-fg-muted">
        {{ __('signed spec → :count regions, one tick', ['count' => count($regions)]) }}
    </p>
</div>
