{{--
    Result page shown after a one-click unsubscribe. The subscription row is
    already gone by the time this renders, so no page context is needed.

    Same standalone shell and the same tokens as the other two subscribe results;
    see confirmed.blade.php for why this flow carries its own head.
--}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>Unsubscribed</title>

        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-surface text-fg antialiased">
        <div class="mx-auto max-w-lg px-4 py-16 text-center">
            <h1 class="text-xl font-semibold">You are unsubscribed</h1>
            <p class="mt-2 text-fg-muted">
                You will no longer receive incident updates. You can subscribe again any time
                from the status page.
            </p>
        </div>
    </body>
</html>
