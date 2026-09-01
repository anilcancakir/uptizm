import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart'
    show DatabaseNotification;

import '../../../app/enums/status_key.dart';
import '../status_dot/index.dart';
import 'notification_center.recipe.dart';

/// The kind of an in-app notification feed item.
///
/// Mirrors the design lab's `NotificationKind` (`down / up / degraded /
/// incident / resolved / ai`). Each kind maps to a monitoring [StatusKey] for
/// the leading [StatusDot] tone, so an outage alert reads `down`, an AI anomaly
/// reads `ai`, and a recovery reads `up`.
enum AppNotificationKind {
  /// A monitor went down.
  down,

  /// A monitor recovered.
  up,

  /// A monitor degraded.
  degraded,

  /// An incident was opened.
  incident,

  /// An incident was resolved.
  resolved,

  /// Uptizm AI flagged an anomaly.
  ai;

  /// Maps the feed kind to the monitoring [StatusKey] driving the dot tone.
  StatusKey get status => switch (this) {
    AppNotificationKind.down => StatusKey.down,
    AppNotificationKind.up => StatusKey.up,
    AppNotificationKind.degraded => StatusKey.degraded,
    AppNotificationKind.incident => StatusKey.down,
    AppNotificationKind.resolved => StatusKey.up,
    AppNotificationKind.ai => StatusKey.ai,
  };
}

/// The backend event type each feed kind is published under.
///
/// The key is the row's `type` as the API serves it: `NotificationResource`
/// publishes `data['type']` there (falling back to the notification's class
/// basename), and `backend/app/Notifications/` writes `incident_opened`,
/// `incident_escalated` and `incident_resolved` into it. The monitor entries
/// carry the vocabulary `magic_notifications` used to hardcode and now refuses
/// to, because it belongs to one monitoring product rather than to every app.
///
/// This map is what [AppServiceProvider.registerNotificationSurface] walks to
/// register one `notifications.icon` slot per type; a type absent from it is
/// answered by the `default` slot instead.
const Map<String, AppNotificationKind> kNotificationKindsByEventType =
    <String, AppNotificationKind>{
      'monitor_down': AppNotificationKind.down,
      'monitor_up': AppNotificationKind.up,
      'monitor_degraded': AppNotificationKind.degraded,
      'incident_opened': AppNotificationKind.incident,
      'incident_escalated': AppNotificationKind.incident,
      'incident_resolved': AppNotificationKind.resolved,
    };

/// The route a notification row opens.
///
/// Reads the ids the backend puts in the payload (`IncidentOpened::toArray()`
/// carries `incident_id`, `monitor_id`, `monitor_name`, `severity`, `kind`),
/// preferring the incident because that is the page an on-call responder needs
/// first; a payload naming only a monitor opens the monitor, and one naming
/// neither falls back to the preference screen rather than nowhere.
///
/// `toString()` rather than a `String` cast: a deployment that has not switched
/// its primary keys to UUIDs serves integer ids, and a row that throws while
/// being decoded takes the whole bell down with it.
String notificationRouteFor(DatabaseNotification notification) {
  final String? incidentId = notification.data['incident_id']?.toString();
  if (incidentId != null && incidentId.isNotEmpty) {
    return '/incidents/$incidentId';
  }

  final String? monitorId = notification.data['monitor_id']?.toString();
  if (monitorId != null && monitorId.isNotEmpty) {
    return '/monitors/$monitorId';
  }

  return '/settings/notifications';
}

/// **The notification-row indicator.**
///
/// Uptizm's contribution to the notification centre `magic_notifications` now
/// ships: the leading status dot on one row. The list, the read state, the
/// mark-all action, the empty state and the relative-time formatter all live in
/// the package, and what a notification KIND looks like is the one thing the
/// package cannot know, so it asks for it through the `notifications.icon` slot
/// family and this is what uptizm answers with.
///
/// It is still a widget rather than a helper because a slot builder returns
/// one: the package wraps it in a 32px neutral circle, which the `size-8` tile
/// from [notificationCenterRecipe] covers exactly, so the row reads in uptizm's
/// own status tones instead of the package's neutral bell.
///
/// ### Example Usage:
///
/// ```dart
/// Notify.view.slot(
///   NotificationViewRegistry.typeIconSlotView,
///   'incident_opened',
///   (context) => const NotificationCenter(kind: AppNotificationKind.incident),
/// );
/// ```
@immutable
class NotificationCenter extends StatelessWidget {
  /// The feed kind controlling the tile tint and the dot tone.
  final AppNotificationKind kind;

  /// Creates a [NotificationCenter] indicator for [kind].
  const NotificationCenter({super.key, required this.kind});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: notificationCenterRecipe(
        variants: {kNotificationCenterKindAxis: kind.name},
      ),
      child: StatusDot(kind.status, size: StatusDotSize.md),
    );
  }
}
