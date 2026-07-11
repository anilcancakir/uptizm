{{--
    Uniform "check your inbox" response for a subscribe POST. Rendered
    identically for a fresh subscribe and a deduped repeat, so it never reveals
    whether the address was already subscribed. Standalone shell (the public
    status flow runs off the app chrome).
--}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Check your inbox</title>
    </head>
    <body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #111827; background: #f9fafb;">
        <div style="max-width: 32rem; margin: 4rem auto; padding: 0 1rem; text-align: center;">
            <h1 style="font-size: 20px; font-weight: 600;">Check your inbox</h1>
            <p style="color: #6b7280;">
                If the address you entered can subscribe to {{ $page->name }}, a confirmation
                email is on its way. Click the link inside to finish subscribing.
            </p>
        </div>
    </body>
</html>
