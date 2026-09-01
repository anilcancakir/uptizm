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
    },
    'database': {
      'enabled': true,
      'polling_interval': 30, // seconds
    },
    'soft_prompt': {
      'enabled': true,
    },
  },
};
