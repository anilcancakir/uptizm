{{--
    The head tags every page on the public marketing surface must emit identically:
    the title, the page's own description, the theme colours, and the canonical plus
    hreflang set.

    EXTRACTED BECAUSE TWO LAYOUTS NEED IT AND MAY NOT DRIFT

    `marketing/layout.blade.php` renders the marketing chrome (sticky nav, footer,
    consent banner). `marketing/service-layout.blade.php` renders a STANDALONE status
    document with none of that, because the service catalog's pages are status pages
    and a nav bar with a "Start free" button is not what a reader of one came for.

    Both are indexed, in every supported language, and the block below is exactly the
    part that decides how. Copying it into the second layout would have been the
    plan's own forbidden "copy-paste with slight variation" on the surface where a
    slight variation means a page declaring itself a duplicate of another. So it lives
    once, and `ChromeTest` and `LegalPagesTest` cover both layouts through it.

    WHAT THE INCLUDING LAYOUT MUST HAVE IN SCOPE

    All of it comes from `App\Support\Marketing\ChromeData`, spread into the view data
    by the controller, so a page that forgets one throws rather than rendering a head
    with a hole in it: $summary, $canonicalUrl, $localeLinks, $defaultLocaleUrl.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

{{-- The brand alone for the home page, and the page's own name in front of it
     everywhere else: a browser tab, a bookmark and a search result all show the
     beginning of this string, so the distinguishing half goes first. --}}
<title>@yield('title', config('app.name'))</title>

{{-- Every page describes ITSELF here. A crawler and a link preview both read it,
     and the hero shows a short line per act rather than a paragraph, so this is
     the only place the landing page's full sentence survives at all. A page
     reusing another page's summary tells a crawler they are the same document. --}}
<meta name="description" content="{{ $summary }}">

<meta name="theme-color" content="#008560" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#07090c" media="(prefers-color-scheme: dark)">

{{--
    One canonical URL per language, and every language declared as an alternate of
    every other.

    The locale is carried by the path alone: `/` for the default language,
    `/<code>` for each of the others. No redirect on Accept-Language, which is the
    opposite of what the Flutter client does. The client can negotiate freely
    because nothing indexes it; a public page that redirects on browser language
    shows a crawler only one of its languages and traps a visitor who wants the
    other one.

    All three of these are THIS page's URLs, composed by ChromeData from the page's
    own path. They used to name the landing route directly, which was correct while
    the landing page was the only page and became a lie the moment a second one
    rendered through the same head: every legal page would have declared itself a
    duplicate of the home page.

    `x-default` is the fallback for a visitor whose language we do not speak, and it
    points at this page in the default language rather than at the apex.
--}}
<link rel="canonical" href="{{ $canonicalUrl }}">

@foreach ($localeLinks as $link)
    <link rel="alternate" hreflang="{{ $link['code'] }}" href="{{ $link['url'] }}">
@endforeach

<link rel="alternate" hreflang="x-default" href="{{ $defaultLocaleUrl }}">
