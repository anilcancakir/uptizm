{{--
    Confirmation result page shown after a subscriber clicks their single-use
    confirm link. `$page` is the status page they now follow.

    Its own shell, like the other two subscribe results: this flow runs off the app
    chrome, and the status layout needs the assembled view model it has no reason to
    build. It styles itself from the same stylesheet and the same tokens as the
    status page, so a reader who lands here from a dark status page does not get a
    white flash and a different product.
--}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>Subscription confirmed</title>

        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-surface text-fg antialiased">
        <div class="mx-auto max-w-lg px-4 py-16 text-center">
            <h1 class="text-xl font-semibold">You are subscribed</h1>
            <p class="mt-2 text-fg-muted">
                You will now receive incident updates for {{ $page->name }}. Every email
                includes a one-click unsubscribe link.
            </p>
        </div>
    </body>
</html>
