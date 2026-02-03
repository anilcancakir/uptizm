import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:fluttersdk_magic_notifications/fluttersdk_magic_notifications.dart';
import 'package:uptizm/resources/views/notifications/notifications_list_view.dart';

/// Helper function to wrap widgets with WindTheme for testing
Widget wrapWithTheme(Widget child) {
  return MaterialApp(
    home: WindTheme(data: WindThemeData(), child: child),
  );
}

/// Mock NotificationManager for testing
class MockNotificationManager {
  final StreamController<List<DatabaseNotification>> _controller =
      StreamController<List<DatabaseNotification>>.broadcast();

  List<DatabaseNotification> _notifications = [];
  bool _markedAllAsRead = false;

  Stream<List<DatabaseNotification>> notifications() => _controller.stream;

  void addNotifications(List<DatabaseNotification> notifications) {
    _notifications = notifications;
    _controller.add(_notifications);
  }

  Future<void> markAsRead(String id) async {
    final index = _notifications.indexWhere((n) => n.id == id);
    if (index != -1) {
      _notifications[index] = _notifications[index].copyWith(
        readAt: DateTime.now(),
      );
      _controller.add(_notifications);
    }
  }

  Future<void> markAllAsRead() async {
    _markedAllAsRead = true;
    _notifications = _notifications
        .map((n) => n.copyWith(readAt: DateTime.now()))
        .toList();
    _controller.add(_notifications);
  }

  Future<void> deleteNotification(String id) async {
    _notifications.removeWhere((n) => n.id == id);
    _controller.add(_notifications);
  }

  void dispose() {
    _controller.close();
  }
}

void main() {
  late MockNotificationManager mockManager;

  setUp(() {
    mockManager = MockNotificationManager();
  });

  tearDown(() {
    mockManager.dispose();
  });

  group('NotificationsListView', () {
    testWidgets('displays notifications list', (tester) async {
      final notifications = [
        DatabaseNotification(
          id: '1',
          type: 'monitor_down',
          title: 'Monitor Down',
          body: 'api.example.com is not responding',
          data: {},
          createdAt: DateTime.now().subtract(const Duration(minutes: 5)),
        ),
        DatabaseNotification(
          id: '2',
          type: 'monitor_up',
          title: 'Monitor Up',
          body: 'api.example.com is back online',
          data: {},
          createdAt: DateTime.now().subtract(const Duration(hours: 1)),
          readAt: DateTime.now().subtract(const Duration(minutes: 30)),
        ),
      ];

      await tester.pumpWidget(
        wrapWithTheme(
          NotificationsListView(
            notificationStream: mockManager.notifications(),
            onMarkAsRead: mockManager.markAsRead,
            onMarkAllAsRead: mockManager.markAllAsRead,
            onDelete: mockManager.deleteNotification,
          ),
        ),
      );

      // Emit notifications
      mockManager.addNotifications(notifications);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));

      // Verify page title (uses translation key when no translation loaded)
      expect(find.text('notifications.title'), findsOneWidget);

      // Verify notifications are displayed
      expect(find.text('Monitor Down'), findsOneWidget);
      expect(find.text('Monitor Up'), findsOneWidget);
    });

    testWidgets('shows empty state when no notifications', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          NotificationsListView(
            notificationStream: mockManager.notifications(),
            onMarkAsRead: mockManager.markAsRead,
            onMarkAllAsRead: mockManager.markAllAsRead,
            onDelete: mockManager.deleteNotification,
          ),
        ),
      );

      // Emit empty list
      mockManager.addNotifications([]);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));

      // Verify empty state message (uses translation key when no translation loaded)
      expect(find.text('notifications.empty'), findsOneWidget);
    });

    testWidgets('shows loading state initially', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          NotificationsListView(
            notificationStream: mockManager.notifications(),
            onMarkAsRead: mockManager.markAsRead,
            onMarkAllAsRead: mockManager.markAllAsRead,
            onDelete: mockManager.deleteNotification,
          ),
        ),
      );

      // Verify loading indicator
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('mark all as read button works', (tester) async {
      final notifications = [
        DatabaseNotification(
          id: '1',
          type: 'test',
          title: 'Test',
          body: 'Body',
          data: {},
          createdAt: DateTime.now(),
        ),
      ];

      await tester.pumpWidget(
        wrapWithTheme(
          NotificationsListView(
            notificationStream: mockManager.notifications(),
            onMarkAsRead: mockManager.markAsRead,
            onMarkAllAsRead: mockManager.markAllAsRead,
            onDelete: mockManager.deleteNotification,
          ),
        ),
      );

      // Emit notifications
      mockManager.addNotifications(notifications);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));

      // Find and tap mark all as read button (uses translation key)
      final markAllButton = find.text('notifications.mark_all_read');
      expect(markAllButton, findsOneWidget);

      await tester.tap(markAllButton);
      await tester.pump();

      // Verify markAllAsRead was called
      expect(mockManager._markedAllAsRead, isTrue);
    });
  });
}
