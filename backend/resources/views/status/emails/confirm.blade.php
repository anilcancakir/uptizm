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
        <title>{{ __('status.emails.confirm.heading') }}</title>
    </head>
    <body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #111827;">
        <h1 style="font-size: 18px; font-weight: 600;">{{ __('status.emails.confirm.heading') }}</h1>

        {{-- The page name keeps its `<strong>`, so the sentence around it is a
             translated PREFIX rather than one string with a `:page` parameter:
             emitting the markup through a parameter would need an UNESCAPED echo,
             which this surface does not allow. Each locale therefore writes a
             clause that ENDS where the name begins. --}}
        <p>{{ __('status.emails.confirm.intro_before_page') }} <strong>{{ $pageName }}</strong>.</p>
        <p>{{ __('status.emails.confirm.instruction') }}</p>

        <p>
            <a href="{{ $confirmUrl }}" style="display: inline-block; padding: 10px 18px; background-color: #008560; color: #ffffff; border-radius: 8px; text-decoration: none;">
                {{ __('status.emails.confirm.action') }}
            </a>
        </p>

        <p style="font-size: 12px; color: #6b7280;">
            {{ __('status.emails.confirm.ignore') }}
        </p>
    </body>
</html>
