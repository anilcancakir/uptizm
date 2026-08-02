{{--
    Public status page. Composes the standalone layout with the sections in
    their fixed order: brand header, overall banner, planned maintenance,
    components, incidents, an optional subscribe box, and the footer. `$vm` is
    the field-allowlisted {@see \App\Http\ViewModels\StatusPageViewModel} the
    controller resolved behind the privacy gate; nothing here reads the
    request or a raw monitor field.

    Planned maintenance sits above the components deliberately: a visitor who
    arrives during a window should read that the work was announced before
    reading the degraded row it explains. The section renders nothing at all
    when no window is open or upcoming.
--}}
@extends('status.layout')

@section('content')
    @include('status.partials.brand-header')
    @include('status.partials.status-banner')
    @include('status.partials.maintenances')
    @include('status.partials.components')
    @include('status.partials.incidents')

    @if ($vm->page['subscriptions_enabled'])
        @include('status.partials.subscribe-box')
    @endif

    @include('status.partials.footer')
@endsection
