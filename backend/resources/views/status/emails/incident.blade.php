{{--
    Incident announcement, sent to ONE confirmed subscriber of a page that
    publishes an affected component. `$title`, `$impactLabel`, `$startedAt` and
    `$componentNames` are the incident as the public sees it; `$unsubscribeUrl`
    is the recipient's own one-click opt-out, built from configuration so it
    survives in an inbox. All dynamic text is auto-escaped.

    Deliberately absent: the severity (an internal triage grade, not a statement
    to a customer) and the timeline notes (each carries its own `is_public`
    flag, which this mail has no way to honour per-note).
--}}
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('status.emails.incident.heading') }}</title>
    </head>
    <body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #111827;">
        <h1 style="font-size: 18px; font-weight: 600;">{{ __('status.emails.incident.heading') }}</h1>

        {{-- A translated SUFFIX, so the page name keeps its `<strong>` without the
             markup travelling through a translation parameter (which would need an
             UNESCAPED echo). Mirrors the maintenance template exactly. --}}
        <p><strong>{{ $pageName }}</strong> {{ __('status.emails.incident.intro_after_page') }}</p>

        <p style="font-size: 16px; font-weight: 600; margin-bottom: 4px;">{{ $title }}</p>

        <p style="margin-top: 0;">
            {{ $impactLabel }} &middot; {{ $startedAt }}
        </p>

        @if ($componentNames !== [])
            <p style="margin-bottom: 4px;">{{ __('status.emails.incident.components') }}</p>
            <ul style="margin-top: 0; padding-left: 18px;">
                @foreach ($componentNames as $componentName)
                    <li>{{ $componentName }}</li>
                @endforeach
            </ul>
        @endif

        <p style="font-size: 12px; color: #6b7280;">
            {{ __('status.emails.incident.footer', ['page' => $pageName]) }}
            <a href="{{ $unsubscribeUrl }}" style="color: #6b7280;">{{ __('status.emails.incident.unsubscribe') }}</a>.
        </p>
    </body>
</html>
