import 'package:flutter/foundation.dart' show kIsWeb;

import '../app/support/env_strings.dart' show envString;

/// Notifications configuration.
///
/// Wires `magic_notifications`' `NotificationServiceProvider`, which reads
/// only `notifications.*` (see its docblock). The push app id is the one
/// value here that differs between the dev and production deployments, so it
/// goes through [envString] like `REVERB_APP_KEY` and `SENTRY_DSN` do: a
/// present-but-blank key must resolve to the empty-string fallback, never to
/// the two-quote-character literal a raw `env()` read would leave behind on
/// a value written `ONESIGNAL_APP_ID=""`.
/// See: https://magic.fluttersdk.com/docs/notifications
Map<String, dynamic> get notificationsConfig => {
  'notifications': {
    'push': {
      // The only driver `magic_notifications` ships. Read through [envString]
      // rather than hardcoded so a consumer that registers a driver of its
      // own with `Notify.extend` can select it without a code change here,
      // even though no `.env` file declares this key today.
      'driver': envString('NOTIFICATIONS_PUSH_DRIVER', 'onesignal'),

      // Public by design: the Web SDK needs this client-side to open its own
      // socket, and it carries no send capability. The OneSignal REST API
      // key that CAN send notifications is server-only and never belongs in
      // a bundled `.env`.
      'app_id': envString('ONESIGNAL_APP_ID', ''),

      // Legacy-only: pre-16.4 Safari (before native Web Push) needs a
      // onesignal.com-hosted Safari site id to receive push at all. Every
      // other platform leaves this blank.
      'safari_web_id': envString('ONESIGNAL_SAFARI_WEB_ID', ''),

      'notify_button_enabled': false,

      // Neither of these two varies between the dev and production
      // deployments (they name a code convention, not a deployment value),
      // so they stay plain literals rather than routing through [envString],
      // the same divergence `deeplink.dart`'s `team_id`/`bundle_id` already
      // carry. A Flutter web build owns the root scope with its own service
      // worker, so the OneSignal worker needs its own path and scope to
      // avoid colliding with `flutter_service_worker.js`.
      'service_worker_path': 'OneSignalSDKWorker.js',
      'service_worker_scope': '/onesignal/',

      // ----------------------------------------------------------------------
      // The permission posture. Insistent, because this app pages people.
      // ----------------------------------------------------------------------

      // Ask on login, but only where the ask is a dialog somebody expects.
      //
      // The package raises the OS request once per launch when an identity is
      // declared, and on mobile that is honest: a person signing into a paging
      // tool expects to be asked, and the platform renders the dialog directly
      // behind the sign-in that explains what it is for.
      //
      // On the web the same call either does nothing or does harm. MDN's
      // "Using the Notifications API" guide: "going forward browsers will
      // explicitly disallow notification permission requests not triggered in
      // response to a user gesture. Firefox is already doing this from version
      // 72, for example, and Safari has done it for some time." A DISALLOWED
      // request is not a spent one: the permission is left at `default`, which
      // means "the user has not been asked yet", so on those two an automatic
      // request at login accomplishes precisely nothing. Chrome never required
      // a gesture, and there it accomplishes the wrong thing: a system prompt
      // in front of an operator who did not ask for one, which is the pattern
      // Chrome's own abuse mitigation punishes, and a dismissal escalates that
      // origin toward being blocked outright. `denied` is the state no code can
      // recover, and the web has no in-app route back to it at all (no API
      // opens the site settings panel from a page), so an engineer who gets
      // there sits in front of a product that quietly cannot page them.
      //
      // The reason this note is as long as it is: an earlier version defended
      // this same value by claiming a gesture-less web request silently SPENDS
      // the one-shot ask. It does not, and a comment that defends a correct
      // decision with a wrong fact is how the decision gets reversed by the
      // next reader who checks the fact.
      //
      // So web leans on the reminder below instead, whose button is a real tap
      // and therefore a real gesture, raised at a moment the operator chose.
      // That costs a little: a web operator is not asked until the reminder is
      // on screen.
      'auto_request_on_login': !kIsWeb,

      // Come back roughly daily to a device that turned the reminder down.
      //
      // `0` and an absent key both mean NEVER, so leaving this out is not a
      // neutral default: it is the posture of an app that asks once and gives
      // up. A device that cannot be paged is an outage nobody hears, so it has
      // to keep saying so.
      //
      // 20 rather than 24 on purpose. A 24-hour interval pins every reminder to
      // the same moment of the operator's day, which is the moment they were
      // already busy enough to say "not now"; 20 walks it across the working
      // day while still landing at most once per on-call day.
      'reprompt_after_hours': 20,

      // Keep the route back on a device the OS prompt is spent on.
      //
      // On by default in the driver; stated here because it is load-bearing
      // rather than incidental. It doubles as `canOpenPlatformSettings`, so it
      // is what turns the blocked row from a sentence into a control on iOS and
      // Android: the request lands on the app's settings page, where the
      // permission can be turned back on. An app whose notifications are a
      // convenience can afford to drop that; one whose notifications wake
      // somebody at 3am cannot.
      'fallback_to_settings': true,
    },
    'database': {
      'enabled': true,
      'polling_interval': 30, // seconds
    },
    'soft_prompt': {
      // The app's own reminder, and on the web the ONLY thing that ever asks:
      // `auto_request_on_login` is off there, so switching this off would mean
      // a browser is never asked for push at all.
      'enabled': true,
    },
  },
};
