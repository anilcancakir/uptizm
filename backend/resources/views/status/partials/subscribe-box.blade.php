{{--
    Subscribe form: only rendered when the page owner has turned subscriptions
    on. Posts to the already-registered `status.subscribe` route name; the
    controller behind it lands in a later step. No CSRF token: the public
    status page runs on the session-less `static` middleware group, so there
    is no session to mint one from.
--}}
@php
    // Tenant data, sanitised to a hex literal upstream, and the only inline colour
    // on the page. See partials/brand-header.blade.php for why a fixed colour takes
    // a fixed white label instead of a token that would flip out from under it.
    $brandColor = $vm->page['brand_color'] ?? null;
@endphp

<section class="mb-6 rounded-lg border border-border bg-surface-container px-5 py-4">
    <h2 class="mb-1 text-sm font-semibold text-fg-muted uppercase">{{ __('status.subscribe.heading') }}</h2>
    <p class="mb-3 text-sm text-fg-muted">{{ __('status.subscribe.body', ['page' => $vm->page['name']]) }}</p>

    <form method="POST" action="{{ route('status.subscribe', $vm->page['slug']) }}" class="flex flex-col gap-2 sm:flex-row">
        {{-- The language this visitor was reading when they subscribed, so their
             confirmation mail and every announcement after it answer in it.

             A form field is the ONLY honest channel for it: the subscribe route
             deliberately carries no locale segment (one POST target per page,
             not one per language), and this surface sends no cookie and holds no
             session, so nothing else survives the hop. Without it every
             subscription falls back to the page's canonical language and a
             Turkish reader who subscribed from `/tr/s/{slug}` gets English mail.
             `SubscribeController` validates it against the supported list and
             falls back to the page's canonical language for anything else, so a
             hand-edited value costs the subscriber nothing and never refuses the
             subscription. --}}
        <input type="hidden" name="locale" value="{{ app()->getLocale() }}">

        {{-- `rounded-base` (8px), not `md`: DESIGN.md puts inputs and small controls
             on 8px and buttons on 12px, and this field was the one element on the page
             using a radius its own design system assigns to something else. --}}
        <input
            type="email"
            name="email"
            required
            placeholder="you@example.com"
            class="w-full flex-1 rounded-base border border-border bg-surface-container-high px-3 py-2 text-sm text-fg outline-none focus-visible:border-primary"
        >
        <button
            type="submit"
            @class([
                'rounded-md px-4 py-2 text-sm font-semibold',
                'text-white' => $brandColor !== null,
                'bg-primary text-on-primary' => $brandColor === null,
            ])
            @if ($brandColor !== null) style="background-color: {{ $brandColor }}" @endif
        >
            {{ __('status.subscribe.action') }}
        </button>
    </form>
</section>
