import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:uptizm/resources/views/notifications/notifications_list_view.dart';

/// Helper function to wrap widgets with WindTheme for testing
Widget wrapWithTheme(Widget child) {
  return MaterialApp(
    home: WindTheme(data: WindThemeData(), child: child),
  );
}

void main() {
  group('NotificationsListView', () {
    testWidgets('renders without crashing', (tester) async {
      await tester.pumpWidget(wrapWithTheme(const NotificationsListView()));

      // Should show loading state initially
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('has correct page title', (tester) async {
      await tester.pumpWidget(wrapWithTheme(const NotificationsListView()));

      // Title should be present (uses translation key when no translation loaded)
      expect(find.text('notifications.title'), findsOneWidget);
    });

    testWidgets('accepts callbacks', (tester) async {
      bool markAsReadCalled = false;
      bool markAllCalled = false;
      bool deleteCalled = false;
      String? navigatedPath;

      await tester.pumpWidget(
        wrapWithTheme(
          NotificationsListView(
            onMarkAsRead: (id) async {
              markAsReadCalled = true;
            },
            onMarkAllAsRead: () async {
              markAllCalled = true;
            },
            onDelete: (id) async {
              deleteCalled = true;
            },
            onNavigate: (path) {
              navigatedPath = path;
            },
          ),
        ),
      );

      // Callbacks should be set (can't easily test without mocking Notify)
      expect(markAsReadCalled, isFalse);
      expect(markAllCalled, isFalse);
      expect(deleteCalled, isFalse);
      expect(navigatedPath, isNull);
    });

    testWidgets('accepts custom perPage', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(const NotificationsListView(perPage: 25)),
      );

      // Should render without error
      expect(find.byType(NotificationsListView), findsOneWidget);
    });
  });

  group('PaginatedNotifications model', () {
    test('fromMap parses response correctly', () {
      final map = {
        'data': [
          {
            'id': '1',
            'type': 'monitor_down',
            'data': {'type': 'monitor_down', 'title': 'Test', 'body': 'Body'},
            'read_at': null,
            'created_at': '2024-01-01T00:00:00.000Z',
          },
        ],
        'meta': {
          'current_page': 2,
          'last_page': 5,
          'per_page': 15,
          'total': 75,
        },
      };

      final result = PaginatedNotifications.fromMap(map);

      expect(result.data.length, equals(1));
      expect(result.currentPage, equals(2));
      expect(result.lastPage, equals(5));
      expect(result.perPage, equals(15));
      expect(result.total, equals(75));
      expect(result.hasNextPage, isTrue);
      expect(result.hasPreviousPage, isTrue);
    });

    test('empty factory creates empty response', () {
      final result = PaginatedNotifications.empty();

      expect(result.data, isEmpty);
      expect(result.currentPage, equals(1));
      expect(result.lastPage, equals(1));
      expect(result.hasNextPage, isFalse);
      expect(result.hasPreviousPage, isFalse);
    });

    test('hasNextPage is false on last page', () {
      final map = {
        'data': [],
        'meta': {
          'current_page': 5,
          'last_page': 5,
          'per_page': 15,
          'total': 75,
        },
      };

      final result = PaginatedNotifications.fromMap(map);
      expect(result.hasNextPage, isFalse);
    });

    test('hasPreviousPage is false on first page', () {
      final map = {
        'data': [],
        'meta': {
          'current_page': 1,
          'last_page': 5,
          'per_page': 15,
          'total': 75,
        },
      };

      final result = PaginatedNotifications.fromMap(map);
      expect(result.hasPreviousPage, isFalse);
    });
  });
}
