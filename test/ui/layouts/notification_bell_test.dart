import 'package:flutter_test/flutter_test.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:uptizm/app/mocks/status.dart';
import 'package:uptizm/ui/components/notification_center/index.dart';

void main() {
  /// Builds a [DatabaseNotification] with the given `data.type`, mirroring
  /// the shape the backend polling endpoint returns (Steps 5-7): a `type`
  /// discriminator inside `data`, alongside `title`/`body`.
  DatabaseNotification notificationWithType(
    String type, {
    String id = 'n1',
    DateTime? createdAt,
    DateTime? readAt,
  }) {
    return DatabaseNotification(
      id: id,
      type: 'App\\Notifications\\MonitorStatusNotification',
      title: 'A monitoring event',
      body: 'Some monitor changed state',
      data: {
        'type': type,
        'incident_id': 'inc-1',
        'monitor_id': 'mon-1',
        'monitor_name': 'Checkout API',
        'severity': 'critical',
      },
      createdAt: createdAt ?? DateTime.now(),
      readAt: readAt,
    );
  }

  group('notificationItemFromDatabaseNotification', () {
    test('maps incident_opened to the incident kind', () {
      final DatabaseNotification notification = notificationWithType(
        'incident_opened',
      );

      final NotificationItem item = notificationItemFromDatabaseNotification(
        notification,
      );

      expect(item.kind, AppNotificationKind.incident);
      expect(item.kind.status, StatusKey.down);
    });

    test('maps incident_resolved to the resolved kind', () {
      final DatabaseNotification notification = notificationWithType(
        'incident_resolved',
      );

      final NotificationItem item = notificationItemFromDatabaseNotification(
        notification,
      );

      expect(item.kind, AppNotificationKind.resolved);
      expect(item.kind.status, StatusKey.up);
    });

    test('falls back to a safe kind for an unknown type without throwing', () {
      final DatabaseNotification notification = notificationWithType(
        'some_future_event_type',
      );

      expect(
        () => notificationItemFromDatabaseNotification(notification),
        returnsNormally,
      );

      final NotificationItem item = notificationItemFromDatabaseNotification(
        notification,
      );
      expect(item.kind, isNotNull);
    });

    test('carries id, title, body, and read-state through', () {
      final DateTime readAt = DateTime.now();
      final DatabaseNotification unread = notificationWithType(
        'incident_opened',
        id: 'n-unread',
      );
      final DatabaseNotification read = notificationWithType(
        'incident_resolved',
        id: 'n-read',
        readAt: readAt,
      );

      final NotificationItem unreadItem =
          notificationItemFromDatabaseNotification(unread);
      final NotificationItem readItem = notificationItemFromDatabaseNotification(
        read,
      );

      expect(unreadItem.id, 'n-unread');
      expect(unreadItem.title, 'A monitoring event');
      expect(unreadItem.detail, 'Some monitor changed state');
      expect(unreadItem.read, isFalse);
      expect(readItem.read, isTrue);
    });
  });

  group('notificationItemsFromDatabaseNotifications', () {
    test('maps a list preserving order', () {
      final List<DatabaseNotification> notifications = [
        notificationWithType('incident_opened', id: 'n1'),
        notificationWithType('incident_resolved', id: 'n2'),
      ];

      final List<NotificationItem> items =
          notificationItemsFromDatabaseNotifications(notifications);

      expect(items.map((i) => i.id).toList(), ['n1', 'n2']);
      expect(items[0].kind, AppNotificationKind.incident);
      expect(items[1].kind, AppNotificationKind.resolved);
    });
  });
}
