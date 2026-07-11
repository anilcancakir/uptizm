{{--
    Double opt-in confirmation email. `$confirmUrl` is a route-generated,
    single-use link derived from the subscriber's confirm token; `$pageName` is
    the status page they subscribed to. All dynamic text is auto-escaped.
--}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Confirm your subscription</title>
    </head>
    <body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #111827;">
        <h1 style="font-size: 18px; font-weight: 600;">Confirm your subscription</h1>

        <p>You asked to receive incident updates for <strong>{{ $pageName }}</strong>.</p>
        <p>Confirm your email address to start receiving them:</p>

        <p>
            <a href="{{ $confirmUrl }}" style="display: inline-block; padding: 10px 18px; background-color: #008560; color: #ffffff; border-radius: 8px; text-decoration: none;">
                Confirm subscription
            </a>
        </p>

        <p style="font-size: 12px; color: #6b7280;">
            If you did not request this, you can ignore this email and nothing will happen.
        </p>
    </body>
</html>
