import 'package:magic/magic.dart';

/// A personal notification delivery channel. Mirrors the three `SettingRow`
/// entries in the React `NotificationsSettingsPage` (in-app, web push,
/// email); team-wide channels (Slack, SMS, webhook) live elsewhere.
enum NotificationChannel {
  /// The notification bell, on web and the mobile app.
  inApp,

  /// Browser push, even when the tab is closed.
  webPush,

  /// Email delivery to the signed-in account.
  email;

  /// Localized human-readable display label.
  String get label => switch (this) {
    NotificationChannel.inApp => trans('uptizm.enums.notification_channel.in_app'),
    NotificationChannel.webPush => trans('uptizm.enums.notification_channel.web_push'),
    NotificationChannel.email => trans('uptizm.enums.notification_channel.email'),
  };
}
