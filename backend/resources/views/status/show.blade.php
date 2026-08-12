{{--
    Public status page. Composes the standalone layout with the sections in
    their fixed order: the language offer and the switcher, brand header,
    overall banner, planned maintenance, components, incidents, an optional
    subscribe box, and the footer. `$vm` is the field-allowlisted
    {@see \App\Http\ViewModels\StatusPageViewModel} the controller resolved
    behind the privacy gate; nothing here reads the request or a raw monitor
    field.

    The two language elements sit in the CONTENT rather than in the layout,
    which is this file's existing division of labour: the layout carries the
    document shell (the head, the stylesheet and the time-localizing script) and
    nothing a visitor reads, while every visible section is composed here. The
    offer comes first because it is addressed to a visitor who cannot read the
    page yet; the switcher follows as the standing control for everyone else.

    Planned maintenance sits above the components deliberately: a visitor who
    arrives during a window should read that the work was announced before
    reading the degraded row it explains. The section renders nothing at all
    when no window is open or upcoming.
--}}
@extends('status.layout')

@section('content')
    @include('status.partials.language-switcher')
    @include('status.partials.brand-header')
    @include('status.partials.status-banner')
    {{-- The offer sits BELOW the verdict, not above it. A reader arriving from an
         alert link is here for one fact, and the banner's colour and dot carry it
         before any language does; a two-line notice plus the switcher above them
         pushed the verdict a third of the way down a phone screen. The offer is
         still inside the first screen at both widths, which is all an offer needs
         to be. --}}
    @include('status.partials.language-banner')
    @include('status.partials.maintenances')
    @include('status.partials.components')
    @include('status.partials.incidents')

    @if ($vm->page['subscriptions_enabled'])
        @include('status.partials.subscribe-box')
    @endif

    @include('status.partials.footer')
@endsection
