import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';

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

/// A single in-app notification feed item.
///
/// A minimal, self-contained model for the mock: there is no notifications
/// fixture in `lib/app/mocks/` yet, so the sample feed lives inline in
/// [kSampleNotifications]. The shape mirrors the design lab's `AppNotification`
/// (id, kind, title, detail, time, to, read).
@immutable
class NotificationItem {
  /// Stable identity used to track read-state locally.
  final String id;

  /// The feed kind controlling the leading status-dot tone.
  final AppNotificationKind kind;

  /// The headline of the notification.
  final String title;

  /// The supporting detail line, or null when there is none to show.
  ///
  /// Nullable because a notification whose body only repeats its title has
  /// nothing to add: `IncidentOpened` builds `title` from the copy catalogue and
  /// `body` from `IncidentTitle::render`, and for a monitor-down incident both
  /// resolve to the same sentence, so the row printed it twice, once under the
  /// other.
  final String? detail;

  /// A human-readable relative timestamp (e.g. "14m ago").
  final String time;

  /// The route this row links to (mirrors the design lab's `to`); the shell
  /// navigates here after marking the item read.
  final String to;

  /// Whether the item is already read in the seed data.
  final bool read;

  /// Creates a [NotificationItem].
  const NotificationItem({
    required this.id,
    required this.kind,
    required this.title,
    required this.time,
    required this.to,
    this.detail,
    this.read = false,
  });
}

/// A small self-contained sample feed for the mock notification center.
///
/// Mirrors the design lab's seed `notifications` list (a handful of items
/// spanning outage, AI anomaly, incident, recovery, and degraded kinds).
const List<NotificationItem> kSampleNotifications = [
  NotificationItem(
    id: 'n1',
    kind: AppNotificationKind.down,
    title: 'Checkout service is down',
    detail: '503s across all regions',
    time: '14m ago',
    to: '/monitors/checkout',
  ),
  NotificationItem(
    id: 'n2',
    kind: AppNotificationKind.ai,
    title: 'AI flagged a latency anomaly',
    detail: 'API gateway · cpu_load climbing',
    time: '4m ago',
    to: '/incidents/api-latency',
  ),
  NotificationItem(
    id: 'n3',
    kind: AppNotificationKind.incident,
    title: 'Incident opened',
    detail: 'Checkout service returning 503s',
    time: '14m ago',
    to: '/incidents/checkout-503',
  ),
  NotificationItem(
    id: 'n4',
    kind: AppNotificationKind.resolved,
    title: 'Incident resolved',
    detail: 'EU region packet loss',
    time: '2h ago',
    to: '/incidents/eu-packet-loss',
    read: true,
  ),
  NotificationItem(
    id: 'n5',
    kind: AppNotificationKind.up,
    title: 'Docs recovered',
    detail: 'Latency back within its band',
    time: '5h ago',
    to: '/incidents/docs-blip',
    read: true,
  ),
];

/// The `data.type` value the backend emits when an incident opens.
const String _kIncidentOpenedType = 'incident_opened';

/// The `data.type` value the backend emits when an incident resolves.
const String _kIncidentResolvedType = 'incident_resolved';

/// Maps a polled [DatabaseNotification] to a [NotificationItem].
///
/// Reads the monitoring event kind from `data.type`
/// (`incident_opened`/`incident_resolved`, emitted by the backend
/// notifications wired in earlier plan steps), not the notification's
/// top-level `type` (a backend class name). An unrecognized `type` falls
/// back to [AppNotificationKind.incident] instead of throwing: the feed must
/// never crash on a payload shape it does not yet know about.
NotificationItem notificationItemFromDatabaseNotification(
  DatabaseNotification notification,
) {
  final String? eventType = notification.data['type'] as String?;
  final AppNotificationKind kind = switch (eventType) {
    _kIncidentOpenedType => AppNotificationKind.incident,
    _kIncidentResolvedType => AppNotificationKind.resolved,
    _ => AppNotificationKind.incident,
  };

  final String? incidentId = notification.data['incident_id']?.toString();
  final String? monitorId = notification.data['monitor_id']?.toString();
  final String to = incidentId != null
      ? '/incidents/$incidentId'
      : monitorId != null
      ? '/monitors/$monitorId'
      : '/settings/notifications';

  return NotificationItem(
    id: notification.id,
    kind: kind,
    title: notification.title,
    // A body identical to the title is not a detail, it is an echo.
    detail: notification.body == notification.title ? null : notification.body,
    time: _relativeTime(notification.createdAt),
    to: to,
    read: notification.isRead,
  );
}

/// Maps a polled notification list to feed items, preserving order.
List<NotificationItem> notificationItemsFromDatabaseNotifications(
  List<DatabaseNotification> notifications,
) {
  return notifications.map(notificationItemFromDatabaseNotification).toList();
}

/// Formats [time] as a short relative string (e.g. "14m ago", "2h ago"),
/// mirroring the sample feed's format so the bells read the same whether
/// they render mock or live data.
String _relativeTime(DateTime time) {
  final Duration elapsed = DateTime.now().difference(time);

  if (elapsed.inMinutes < 1) return trans('uptizm.common.time_just_now');
  if (elapsed.inHours < 1) {
    return trans('uptizm.common.time_minutes_ago', {
      'count': '${elapsed.inMinutes}',
    });
  }
  if (elapsed.inDays < 1) {
    return trans('uptizm.common.time_hours_ago', {
      'count': '${elapsed.inHours}',
    });
  }
  return trans('uptizm.common.time_days_ago', {'count': '${elapsed.inDays}'});
}

/// **The In-App Notification Center**
///
/// A slide-in / overlay panel listing recent notifications (monitoring,
/// incidents, AI). Ported from the design lab's `NotificationCenter`, which
/// renders this same content inside a `DropdownMenu` opened from a bell trigger.
///
/// Here the panel is a standalone widget: the bell trigger and the open/close
/// wiring are the shell's concern (Step 9). The shell mounts this panel inside
/// its own dropdown / overlay and passes an [onClose] callback that the
/// "Notification settings" footer and a tapped row invoke after navigating.
///
/// ### Behavior
///
/// - **Unread tracking** is local: tapping a row marks it read (and invokes
///   [onItemTap]); "Mark all read" marks the whole feed read. Both mutate
///   local state via `setState`, mirroring the design lab's `readIds` set.
/// - **Press feedback, not hover:** every interactive row is a [WAnchor], so it
///   responds to press / ripple on mobile rather than a hover-only affordance
///   (PORTING.md §7).
/// - **Token-only:** the leading dot reuses [StatusDot] (the monitoring status
///   families); the unread marker uses `bg-primary`. No raw hex anywhere.
///
/// ### Example Usage:
///
/// ```dart
/// // Mounted by the shell inside its bell dropdown:
/// NotificationCenter(
///   onClose: () => Navigator.of(context).pop(),
///   onItemTap: (item) => MagicRoute.to('/incidents/${item.id}'),
///   onSettings: () => MagicRoute.to('/settings/notifications'),
/// )
/// ```
class NotificationCenter extends StatefulWidget {
  /// The feed to render. Defaults to the self-contained [kSampleNotifications].
  final List<NotificationItem> items;

  /// Invoked when the panel should close (after a row tap or the settings row).
  final VoidCallback? onClose;

  /// Invoked with the tapped item before the panel closes.
  final void Function(NotificationItem item)? onItemTap;

  /// Invoked when the "Notification settings" footer row is tapped.
  final VoidCallback? onSettings;

  /// Invoked when "Mark all as read" is tapped, so the host can persist the
  /// read state (e.g. `Notify.markAllAsRead()`). The local read set is still
  /// updated for instant panel feedback.
  final VoidCallback? onMarkAllRead;

  /// Creates a [NotificationCenter] panel.
  const NotificationCenter({
    super.key,
    this.items = kSampleNotifications,
    this.onClose,
    this.onItemTap,
    this.onSettings,
    this.onMarkAllRead,
  });

  @override
  State<NotificationCenter> createState() => _NotificationCenterState();
}

class _NotificationCenterState extends State<NotificationCenter> {
  /// Ids that have been read, seeded from the items' `read` flag.
  late final Set<String> _readIds = {
    for (final item in widget.items)
      if (item.read) item.id,
  };

  /// Count of items not yet marked read.
  int get _unread =>
      widget.items.where((item) => !_readIds.contains(item.id)).length;

  /// Marks every item read: local set for instant feedback, plus the
  /// [NotificationCenter.onMarkAllRead] host callback to persist it.
  void _markAll() {
    setState(() => _readIds.addAll(widget.items.map((item) => item.id)));
    widget.onMarkAllRead?.call();
  }

  /// Marks a single item read, fires [onItemTap], then closes the panel.
  void _open(NotificationItem item) {
    setState(() => _readIds.add(item.id));
    widget.onItemTap?.call(item);
    widget.onClose?.call();
  }

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the outer panel className from the recipe.
    final String panelClass = notificationCenterRecipe();

    // 2. Build the panel: header, separator, body (rows or empty state),
    //    separator, footer. Mirrors the design lab's dropdown content order.
    return WDiv(
      className: panelClass,
      children: [
        _buildHeader(),
        _buildSeparator(),
        if (widget.items.isEmpty)
          _buildEmptyState()
        else
          for (final item in widget.items) _buildRow(item),
        _buildSeparator(),
        _buildSettingsRow(),
      ],
    );
  }

  /// Header: the localized "Notifications" label and a "Mark all read" action
  /// that is only shown while unread items remain.
  ///
  /// A Flutter [Row] drives the layout (an [Expanded] label that can truncate
  /// plus the trailing action) so the constraint behavior is deterministic; a
  /// flex `WDiv` leaves its main-axis size ambiguous in the bounded panel.
  Widget _buildHeader() {
    final bool hasUnread = _unread > 0;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      // A Wrap rather than a Row, because the panel is `w-80` (320px, so a
      // 294px content row) and the two labels do not always share one line.
      // Measured against the shipped catalogues at their real styles: the title
      // needs 157 in Turkish and 186 in English, the action 196 in English, and
      // the Row put them side by side unconditionally. English silently clipped
      // its title to 98px, and Turkish threw `A RenderFlex overflowed by 61
      // pixels` outright, which the suite never saw because this file's loader
      // hands out English literals.
      //
      // Wrap drops the action to its own line instead of clipping either label,
      // so both locales now render whole: Turkish fits on one line (157 + 123),
      // English takes two. `truncate` stays on both as the last resort for a
      // single label longer than a whole line, which no shipped string is.
      child: Wrap(
        spacing: 8,
        runSpacing: 4,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          WText(
            trans('notifications.title'),
            className: 'text-sm font-semibold text-fg truncate',
          ),
          if (hasUnread)
            WAnchor(
              onTap: _markAll,
              child: WText(
                trans('notifications.mark_all_read'),
                className: 'text-xs text-primary truncate',
              ),
            ),
        ],
      ),
    );
  }

  /// A single notification row: leading status dot, title / detail / time
  /// column, and an unread marker. The whole row is a [WAnchor] for press
  /// feedback (PORTING.md §7).
  ///
  /// The leading dot and the trailing unread marker are nudged down with
  /// `mt-1.5` so they align with the first text line, mirroring the design
  /// lab's `items-start gap-3` + `mt-1.5` row.
  Widget _buildRow(NotificationItem item) {
    final bool isRead = _readIds.contains(item.id);

    return WAnchor(
      onTap: () => _open(item),
      child: WDiv(
        className: 'px-3 py-2 hover:bg-surface-container',
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Leading status dot, nudged to align with the first text line.
            WDiv(
              className: 'mt-1.5 mr-3',
              child: StatusDot(item.kind.status, size: StatusDotSize.sm),
            ),

            // Title / detail / time column; the detail truncates within it.
            Expanded(
              child: WDiv(
                className: 'flex flex-col min-w-0',
                children: [
                  WText(
                    item.title,
                    className: isRead
                        ? 'text-sm text-fg-muted'
                        : 'text-sm font-medium text-fg',
                  ),
                  if (item.detail case final String detail)
                    WText(
                      detail,
                      className: 'text-xs text-fg-muted truncate',
                    ),
                  WText(
                    item.time,
                    className: 'font-mono text-xs text-fg-muted',
                  ),
                ],
              ),
            ),

            // Unread marker dot.
            if (!isRead)
              WDiv(
                className:
                    'mt-1.5 ml-3 size-2 shrink-0 rounded-full bg-primary',
              ),
          ],
        ),
      ),
    );
  }

  /// The empty state shown when the feed has no items: a centered, muted
  /// "No notifications" line. The design lab seeds a non-empty feed and so
  /// never renders this, but the panel must read sensibly when empty.
  Widget _buildEmptyState() {
    return WDiv(
      className: 'px-3 py-8 flex items-center justify-center',
      child: WText(
        trans('notifications.empty'),
        className: 'text-sm text-fg-muted',
      ),
    );
  }

  /// Footer row linking to the full notification settings page.
  Widget _buildSettingsRow() {
    return WAnchor(
      onTap: () {
        widget.onSettings?.call();
        widget.onClose?.call();
      },
      child: WDiv(
        className: 'px-3 py-2 hover:bg-surface-container',
        child: WText(
          trans('notifications.settings'),
          className: 'text-sm text-fg',
        ),
      ),
    );
  }

  /// A hairline separator between sections.
  Widget _buildSeparator() {
    return WDiv(className: 'border-t border-color-border-subtle');
  }
}
