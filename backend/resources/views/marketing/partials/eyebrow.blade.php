{{--
    The small pill that labels a section. A tinted capsule reads as a designed
    element where bare uppercase text reads as a caption, which is most of the
    difference between a page that looks composed and one that looks generated.

    @param string $text
    @param string $tone  'primary' (default) or 'ai'
--}}
@php
    $toneClasses = match ($tone ?? 'primary') {
        'ai' => 'bg-ai-soft text-ai-soft-foreground',
        default => 'bg-primary-container text-accent',
    };
@endphp

<span data-reveal class="inline-flex items-center gap-2 rounded-full {{ $toneClasses }} px-3 py-1 text-label-sm uppercase tracking-[0.12em]">
    {{-- The dot breathes. Small thing, but it is the only motion above the fold
         that does not stop, and this component replaced an earlier eyebrow whose
         pulsing dot got dropped in the refactor, which left the hero completely
         still one second after load. --}}
    <span data-breathe class="size-1.5 rounded-full bg-current"></span>
    {{ $text }}
</span>
