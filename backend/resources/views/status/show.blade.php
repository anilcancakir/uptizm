{{--
    Public status page. Composes the standalone layout with the six sections
    in their fixed order: brand header, overall banner, components,
    incidents, an optional subscribe box, and the footer. `$vm` is the
    field-allowlisted {@see \App\Http\ViewModels\StatusPageViewModel} the
    controller resolved behind the privacy gate; nothing here reads the
    request or a raw monitor field.
--}}
@extends('status.layout')

@section('content')
    @include('status.partials.brand-header')
    @include('status.partials.status-banner')
    @include('status.partials.components')
    @include('status.partials.incidents')

    @if ($vm->page['subscriptions_enabled'])
        @include('status.partials.subscribe-box')
    @endif

    @include('status.partials.footer')
@endsection
