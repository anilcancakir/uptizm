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

  /// Human-readable display label.
  String get label => switch (this) {
    NotificationChannel.inApp => 'In-app',
    NotificationChannel.webPush => 'Web push',
    NotificationChannel.email => 'Email',
  };
}
