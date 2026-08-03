{{--
    ONE labelled status statement: a dot, a sentence, the provenance the sentence
    came from, and when it was true.

    Every status value on a service page and on the catalog hub renders through
    this partial, and that is the mechanism behind the plan's Must Have that no
    displayed status fact appears without its provenance. A page cannot forget the
    label, because there is no other way to render a status here, and the label
    itself is decided in one place (the `match` below) so the hub and the page
    cannot word the same provenance differently.

    WHAT THE INCLUDING PAGE MUST PASS

      $provenance  an `App\Enums\StatusProvenance` BACKING VALUE, from the read
                   model. The match below has no default arm, so a third case
                   added to that enum fails loudly here rather than rendering an
                   unlabelled row.
      $provider    the service's name, for the provider-feed label. Never used to
                   describe uptizm's own reading.
      $status      a status on this repo's public ladder, or `unknown`, or null:
                   whatever StatusPresentation recognises. Null and unrecognised
                   both resolve to the NEUTRAL family, never to green.
      $headline    the statement itself, already first-person ("we reached ...")
                   or already a quote ("they report ..."). Passed in rather than
                   built here, because the wording is what the two provenances
                   differ in and it belongs beside the data it describes.
      $detail      a second line, or null.
      $timestamp   an ISO 8601 instant, or null. Rendered as an exact `<time>`
                   value so the page is never wrong about WHEN, even though the
                   relative phrase beside it is rounded.
      $ageSeconds  the age of $timestamp at assembly time, or null.

    The age was computed when the read model was assembled and the model is cached
    for a minute, so the relative phrase can trail the clock by up to that minute.
    The exact timestamp beside it cannot, which is why both are rendered: the
    same trade the customer status page makes with its own "updated Xm ago".

    LAYOUT: this row carries its own `px-5 py-4` and its own bottom divider, so it
    sits flush inside a bordered section card exactly the way a component row does
    on the customer status page (`status/partials/components.blade.php`). The
    including page supplies the card, never padding of its own, or the dividers stop
    reaching the card's edges.
--}}
@php
    /*
     * The label for a provenance, in ONE place. An exhaustive match with no
     * default arm: an unlabelled status value is the single thing this surface may
     * never publish, so a new provenance has to be given words here before it can
     * be rendered anywhere.
     */
    $provenanceLabel = match ($provenance) {
        \App\Enums\StatusProvenance::OwnProbe->value => __('Measured by Uptizm'),
        \App\Enums\StatusProvenance::ProviderFeed->value => __('Published by :service', ['service' => $provider]),
    };

    /*
     * A coarse relative age. Seconds under a minute and a half, minutes above it:
     * finer than that would be precision the 60-second cache cannot honour, and
     * hours would lose the difference between "just now" and "a while ago", which
     * is the entire point of showing it.
     */
    $agePhrase = $ageSeconds === null
        ? null
        : ($ageSeconds < 90
            ? __(':count seconds ago', ['count' => $ageSeconds])
            : __(':count minutes ago', ['count' => intdiv($ageSeconds, 60)]));
@endphp

<div class="flex flex-col gap-1 border-b border-border-subtle px-5 py-4 last:border-b-0">
    <div class="flex flex-wrap items-center gap-2">
        <span
            class="h-2.5 w-2.5 shrink-0 rounded-full {{ \App\Support\StatusPages\StatusPresentation::dotClass((string) $status) }}"
            aria-hidden="true"
        ></span>

        <span class="text-body-lg text-fg">{{ $headline }}</span>

        {{-- The provenance, as a visible chip rather than a tooltip: it has to be
             readable in a screenshot of the page, because the claim the page makes
             is precisely "this number came from here". --}}
        <span class="rounded-full bg-surface-container-high px-2 py-0.5 text-label-sm text-fg-muted">
            {{ $provenanceLabel }}
        </span>
    </div>

    @if ($detail !== null)
        <p class="text-body-md text-fg-muted">{{ $detail }}</p>
    @endif

    @if ($timestamp !== null)
        <p class="text-label-sm text-fg-muted">
            <time datetime="{{ $timestamp }}">{{ $timestamp }}</time>@if ($agePhrase !== null) &middot; {{ $agePhrase }}@endif
        </p>
    @endif
</div>
