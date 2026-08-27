{{--
    Maintenance cancellation, sent to ONE confirmed subscriber of the page the
    window belonged to. `$title` and `$componentNames` are values rather than a
    model: the window is deleted by the time this renders. `$unsubscribeUrl` is
    the recipient's own one-click opt-out, built from configuration so it
    survives in an inbox. All dynamic text is auto-escaped.

    The window's BOUNDS are deliberately absent: the announcement these readers
    already hold carries them, and restating a schedule in the message that
    cancels it invites the reader to diary the wrong thing twice.
--}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('status.emails.maintenance_cancelled.heading') }}</title>
    </head>
    <body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #111827;">
        <h1 style="font-size: 18px; font-weight: 600;">{{ __('status.emails.maintenance_cancelled.heading') }}</h1>

        {{-- A translated SUFFIX, so the page name keeps its `<strong>` without
             the markup travelling through a translation parameter. Mirrors the
             announcement template exactly. --}}
        <p><strong>{{ $pageName }}</strong> {{ __('status.emails.maintenance_cancelled.intro_after_page') }}</p>

        <p style="font-size: 16px; font-weight: 600;">{{ $title }}</p>

        @if ($componentNames !== [])
            <p style="margin-bottom: 4px;">{{ __('status.emails.maintenance_cancelled.components') }}</p>
            <ul style="margin-top: 0; padding-left: 18px;">
                @foreach ($componentNames as $componentName)
                    <li>{{ $componentName }}</li>
                @endforeach
            </ul>
        @endif

        <p style="font-size: 12px; color: #6b7280;">
            {{ __('status.emails.maintenance_cancelled.footer', ['page' => $pageName]) }}
            <a href="{{ $unsubscribeUrl }}" style="color: #6b7280;">{{ __('status.emails.maintenance_cancelled.unsubscribe') }}</a>.
        </p>
    </body>
</html>
