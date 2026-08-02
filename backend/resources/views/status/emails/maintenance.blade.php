{{--
    Scheduled maintenance announcement, sent to ONE confirmed subscriber of the
    page. `$title`, `$description`, `$startsAt`, `$endsAt` and `$componentNames`
    are the window as the public sees it; `$unsubscribeUrl` is the recipient's
    own one-click opt-out, built from configuration so it survives in an inbox.
    All dynamic text is auto-escaped.
--}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Scheduled maintenance</title>
    </head>
    <body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #111827;">
        <h1 style="font-size: 18px; font-weight: 600;">Scheduled maintenance</h1>

        <p><strong>{{ $pageName }}</strong> has planned maintenance coming up.</p>

        <p style="font-size: 16px; font-weight: 600; margin-bottom: 4px;">{{ $title }}</p>

        <p style="margin-top: 0;">
            {{ $startsAt }} &ndash; {{ $endsAt }}
        </p>

        @if (filled($description))
            <p>{{ $description }}</p>
        @endif

        @if ($componentNames !== [])
            <p style="margin-bottom: 4px;">Affected components:</p>
            <ul style="margin-top: 0; padding-left: 18px;">
                @foreach ($componentNames as $componentName)
                    <li>{{ $componentName }}</li>
                @endforeach
            </ul>
        @endif

        <p style="font-size: 12px; color: #6b7280;">
            You are receiving this because you confirmed a subscription to {{ $pageName }}.
            <a href="{{ $unsubscribeUrl }}" style="color: #6b7280;">Unsubscribe</a>.
        </p>
    </body>
</html>
