{{--
    Confirmation result page shown after a subscriber clicks their single-use
    confirm link. `$page` is the status page they now follow.
--}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Subscription confirmed</title>
    </head>
    <body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #111827; background: #f9fafb;">
        <div style="max-width: 32rem; margin: 4rem auto; padding: 0 1rem; text-align: center;">
            <h1 style="font-size: 20px; font-weight: 600;">You are subscribed</h1>
            <p style="color: #6b7280;">
                You will now receive incident updates for {{ $page->name }}. Every email
                includes a one-click unsubscribe link.
            </p>
        </div>
    </body>
</html>
