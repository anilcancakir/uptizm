{{--
    Uniform "check your inbox" response for a subscribe POST. Rendered
    identically for a fresh subscribe and a deduped repeat, so it never reveals
    whether the address was already subscribed. Standalone shell (the public
    status flow runs off the app chrome), styled from the same tokens as the status
    page; see confirmed.blade.php.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>{{ __('status.check_inbox.title') }}</title>

        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-surface text-fg antialiased">
        <div class="mx-auto max-w-lg px-4 py-16 text-center">
            {{-- The same catalogue key as the `<title>` above: it is one sentence,
                 and a second key is how the tab and the heading end up
                 disagreeing. --}}
            <h1 class="text-xl font-semibold">{{ __('status.check_inbox.title') }}</h1>
            <p class="mt-2 text-fg-muted">
                {{ __('status.check_inbox.body', ['page' => $page->name]) }}
            </p>
        </div>
    </body>
</html>
