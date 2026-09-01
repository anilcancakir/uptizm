import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/ui/components/notification_center/index.dart';
import 'package:uptizm/ui/components/notification_center/notification_center.preview.dart';
import 'package:uptizm/ui/components/status_dot/index.dart';

void main() {
  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme].
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: SingleChildScrollView(child: widget)),
      ),
    );
  }

  /// Builds a notification carrying [type] as the backend's event
  /// discriminator, plus the two id keys the route decode reads.
  DatabaseNotification notification({
    String type = 'incident_opened',
    String? incidentId = 'inc-1',
    String? monitorId = 'mon-1',
  }) {
    return DatabaseNotification(
      id: 'n-1',
      type: type,
      title: 'A monitoring event',
      body: 'Some monitor changed state',
      data: <String, dynamic>{
        'type': type,
        'incident_id': ?incidentId,
        'monitor_id': ?monitorId,
      },
      createdAt: DateTime.utc(2026, 8, 19, 16),
    );
  }

  // ---------------------------------------------------------------------------
  // The kind vocabulary: uptizm's half of the package's row
  // ---------------------------------------------------------------------------

  group('AppNotificationKind.status', () {
    test('maps each kind to the expected monitoring status', () {
      expect(AppNotificationKind.down.status, StatusKey.down);
      expect(AppNotificationKind.up.status, StatusKey.up);
      expect(AppNotificationKind.degraded.status, StatusKey.degraded);
      expect(AppNotificationKind.incident.status, StatusKey.down);
      expect(AppNotificationKind.resolved.status, StatusKey.up);
      expect(AppNotificationKind.ai.status, StatusKey.ai);
    });
  });

  group('kNotificationKindsByEventType', () {
    test('answers for every event type the backend emits today', () {
      // `NotificationResource` publishes `data['type']` as the row's `type`,
      // and the three notification classes in `backend/app/Notifications/`
      // write `incident_opened`, `incident_escalated` and `incident_resolved`.
      expect(
        kNotificationKindsByEventType['incident_opened'],
        AppNotificationKind.incident,
      );
      expect(
        kNotificationKindsByEventType['incident_escalated'],
        AppNotificationKind.incident,
      );
      expect(
        kNotificationKindsByEventType['incident_resolved'],
        AppNotificationKind.resolved,
      );
    });

    test('answers for the monitor vocabulary the package no longer carries', () {
      expect(
        kNotificationKindsByEventType['monitor_down'],
        AppNotificationKind.down,
      );
      expect(
        kNotificationKindsByEventType['monitor_up'],
        AppNotificationKind.up,
      );
      expect(
        kNotificationKindsByEventType['monitor_degraded'],
        AppNotificationKind.degraded,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // The route decode
  // ---------------------------------------------------------------------------

  group('notificationRouteFor', () {
    test('prefers the incident over the monitor', () {
      expect(notificationRouteFor(notification()), '/incidents/inc-1');
    });

    test('falls back to the monitor when there is no incident', () {
      expect(
        notificationRouteFor(notification(incidentId: null)),
        '/monitors/mon-1',
      );
    });

    test('falls back to the preference screen when there is neither', () {
      expect(
        notificationRouteFor(
          notification(incidentId: null, monitorId: null),
        ),
        '/settings/notifications',
      );
    });

    test('reads a non-string id without throwing', () {
      // The backend writes an integer id on a non-UUID deployment, and a row
      // that throws while being decoded takes the whole bell down with it.
      final DatabaseNotification numeric = DatabaseNotification(
        id: 'n-2',
        type: 'incident_opened',
        title: 'A monitoring event',
        body: 'body',
        data: const <String, dynamic>{'type': 'incident_opened', 'incident_id': 7},
        createdAt: DateTime.utc(2026, 8, 19, 16),
      );

      expect(notificationRouteFor(numeric), '/incidents/7');
    });
  });

  // ---------------------------------------------------------------------------
  // The recipe carries the kind-to-token mapping
  // ---------------------------------------------------------------------------

  group('notificationCenterRecipe', () {
    test('emits a soft status tint per kind', () {
      String forKind(AppNotificationKind kind) => notificationCenterRecipe(
        variants: {kNotificationCenterKindAxis: kind.name},
      );

      expect(forKind(AppNotificationKind.down), contains('bg-down-soft'));
      expect(forKind(AppNotificationKind.up), contains('bg-up-soft'));
      expect(
        forKind(AppNotificationKind.degraded),
        contains('bg-degraded-soft'),
      );
      expect(forKind(AppNotificationKind.incident), contains('bg-down-soft'));
      expect(forKind(AppNotificationKind.resolved), contains('bg-up-soft'));
      expect(forKind(AppNotificationKind.ai), contains('bg-ai-soft'));
    });

    test('base centres the dot in a round tile', () {
      final String cls = notificationCenterRecipe();

      expect(cls, contains('rounded-full'));
      expect(cls, contains('items-center'));
      expect(cls, contains('justify-center'));
    });
  });

  // ---------------------------------------------------------------------------
  // The widget: one dot, in the kind's tone
  // ---------------------------------------------------------------------------

  testWidgets('renders one StatusDot in the kind tone', (tester) async {
    for (final AppNotificationKind kind in AppNotificationKind.values) {
      await tester.pumpWidget(wrap(NotificationCenter(kind: kind)));
      await tester.pump();

      expect(find.byType(StatusDot), findsOneWidget);
      expect(tester.widget<StatusDot>(find.byType(StatusDot)).status,
          kind.status);
    }
  });

  testWidgets('the reduced component carries no feed of its own', (
    tester,
  ) async {
    // The list, the read-state handling, the mark-all footer, the empty state
    // and the relative-time formatter all live in `magic_notifications` now;
    // what stays here is the one thing the package cannot know, which is what
    // an uptizm notification type looks like.
    await tester.pumpWidget(
      wrap(const NotificationCenter(kind: AppNotificationKind.incident)),
    );
    await tester.pump();

    expect(find.byType(WText), findsNothing);
  });

  testWidgets('preview renders every kind', (tester) async {
    await tester.pumpWidget(wrap(const NotificationCenterPreview()));
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(
      find.byType(NotificationCenter),
      findsNWidgets(AppNotificationKind.values.length),
    );
  });
}
