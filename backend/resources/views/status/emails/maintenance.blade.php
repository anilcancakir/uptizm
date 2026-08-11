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
        <title>{{ __('status.emails.maintenance.heading') }}</title>
    </head>
    <body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #111827;">
        <h1 style="font-size: 18px; font-weight: 600;">{{ __('status.emails.maintenance.heading') }}</h1>

        {{-- A translated SUFFIX, so the page name keeps its `<strong>` without the
             markup travelling through a translation parameter (which would need an
             UNESCAPED echo). Both languages happen to put the name at the head of
             this sentence, so one key covers the rest of it. --}}
        <p><strong>{{ $pageName }}</strong> {{ __('status.emails.maintenance.intro_after_page') }}</p>

        <p style="font-size: 16px; font-weight: 600; margin-bottom: 4px;">{{ $title }}</p>

        <p style="margin-top: 0;">
            {{ $startsAt }} &ndash; {{ $endsAt }}
        </p>

        @if (filled($description))
            <p>{{ $description }}</p>
        @endif

        @if ($componentNames !== [])
            <p style="margin-bottom: 4px;">{{ __('status.emails.maintenance.components') }}</p>
            <ul style="margin-top: 0; padding-left: 18px;">
                @foreach ($componentNames as $componentName)
                    <li>{{ $componentName }}</li>
                @endforeach
            </ul>
        @endif

        <p style="font-size: 12px; color: #6b7280;">
            {{ __('status.emails.maintenance.footer', ['page' => $pageName]) }}
            <a href="{{ $unsubscribeUrl }}" style="color: #6b7280;">{{ __('status.emails.maintenance.unsubscribe') }}</a>.
        </p>
    </body>
</html>
