{{--
    Uniform "check your inbox" response for a subscribe POST. Rendered
    identically for a fresh subscribe and a deduped repeat, so it never reveals
    whether the address was already subscribed. Standalone shell (the public
    status flow runs off the app chrome), styled from the same tokens as the status
    page; see confirmed.blade.php.
--}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>Check your inbox</title>

        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-surface text-fg antialiased">
        <div class="mx-auto max-w-lg px-4 py-16 text-center">
            <h1 class="text-xl font-semibold">Check your inbox</h1>
            <p class="mt-2 text-fg-muted">
                If the address you entered can subscribe to {{ $page->name }}, a confirmation
                email is on its way. Click the link inside to finish subscribing.
            </p>
        </div>
    </body>
</html>
