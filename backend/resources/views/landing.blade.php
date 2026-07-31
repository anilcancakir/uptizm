{{--
    The landing page: six sections inside the shared marketing shell.

    The head, the skip link, the header and the footer moved to
    `marketing/layout.blade.php` when the legal and support pages arrived, and this file
    became one of its consumers. Nothing about the rendered page changed; it is the same
    markup reached through a `@yield`.

    The title is deliberately NOT set here. The layout defaults it to the brand alone,
    which is what the home page should carry: "Uptizm | Uptizm" is what a page-name prefix
    would produce on the one page whose name IS the brand.

    Its own view data (the six sections' copy) comes from ShowLandingController; the
    shell's comes from `App\Support\Marketing\ChromeData`, including `$sections`, the
    in-page anchor list the header nav and the footer render from.
--}}
@extends('marketing.layout')

@section('content')
    @include('marketing.hero')
    @include('marketing.decides')
    @include('marketing.inspects')
    @include('marketing.status-pages')
    @include('marketing.ai-boundary')
    @include('marketing.closing')
@endsection
