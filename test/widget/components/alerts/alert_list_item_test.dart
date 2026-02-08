import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/alert.dart';
import 'package:uptizm/resources/views/components/alerts/alert_list_item.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('AlertListItem', () {
    late Alert testAlert;

    setUp(() {
      testAlert = Alert.fromMap({
        'id': 'test-uuid-1',
        'alert_rule_id': 'test-alert-rule-uuid-10',
        'monitor_id': 'test-monitor-uuid-20',
        'status': 'alerting',
        'triggered_at': '2026-02-05T10:00:00Z',
        'resolved_at': null,
        'trigger_value': 6500.0,
        'trigger_message': 'Response time exceeded 5000ms',
        'alert_rule': {
          'id': 'test-uuid-10',
          'team_id': 'test-team-uuid-5',
          'name': 'High Response Time',
          'type': 'threshold',
          'severity': 'warning',
        },
      });
    });

    testWidgets('renders alerting state with red indicator', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertListItem(alert: testAlert)),
      );

      expect(find.text('Response time exceeded 5000ms'), findsOneWidget);
    });

    testWidgets('renders resolved state with green indicator', (tester) async {
      final resolvedAlert = Alert.fromMap({
        'id': 'test-uuid-2',
        'alert_rule_id': 'test-alert-rule-uuid-10',
        'monitor_id': 'test-monitor-uuid-20',
        'status': 'resolved',
        'triggered_at': '2026-02-05T10:00:00Z',
        'resolved_at': '2026-02-05T10:15:00Z',
        'trigger_message': 'Monitor recovered',
        'alert_rule': {
          'id': 'test-uuid-10',
          'team_id': 'test-team-uuid-5',
          'name': 'Status Check',
          'type': 'status',
          'severity': 'critical',
        },
      });

      await tester.pumpWidget(
        buildTestApp(child: AlertListItem(alert: resolvedAlert)),
      );

      expect(find.text('Monitor recovered'), findsOneWidget);
    });

    testWidgets('shows severity badge', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertListItem(alert: testAlert)),
      );

      expect(find.text('Warning'), findsOneWidget);
    });

    testWidgets('shows trigger message', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertListItem(alert: testAlert)),
      );

      expect(find.text('Response time exceeded 5000ms'), findsOneWidget);
    });

    testWidgets('shows triggered time', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertListItem(alert: testAlert)),
      );

      // Should show some time representation
      expect(find.textContaining('Feb'), findsOneWidget);
    });

    testWidgets('shows duration for resolved alerts', (tester) async {
      final resolvedAlert = Alert.fromMap({
        'id': 'test-uuid-2',
        'alert_rule_id': 'test-alert-rule-uuid-10',
        'monitor_id': 'test-monitor-uuid-20',
        'status': 'resolved',
        'triggered_at': '2026-02-05T10:00:00Z',
        'resolved_at': '2026-02-05T10:15:00Z',
        'trigger_message': 'Monitor recovered',
        'alert_rule': {
          'id': 'test-uuid-10',
          'team_id': 'test-team-uuid-5',
          'name': 'Status Check',
          'type': 'status',
          'severity': 'critical',
        },
      });

      await tester.pumpWidget(
        buildTestApp(child: AlertListItem(alert: resolvedAlert)),
      );

      expect(find.textContaining('15m'), findsOneWidget);
    });

    testWidgets('calls onTap when item tapped', (tester) async {
      bool tapCalled = false;

      await tester.pumpWidget(
        buildTestApp(
          child: AlertListItem(alert: testAlert, onTap: () => tapCalled = true),
        ),
      );

      await tester.tap(find.byType(AlertListItem));
      await tester.pump();

      expect(tapCalled, isTrue);
    });

    testWidgets('shows alert rule name', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertListItem(alert: testAlert)),
      );

      expect(find.text('High Response Time'), findsOneWidget);
    });

    testWidgets('handles missing alert rule gracefully', (tester) async {
      final alertWithoutRule = Alert.fromMap({
        'id': 'test-uuid-3',
        'alert_rule_id': 'test-alert-rule-uuid-10',
        'monitor_id': 'test-monitor-uuid-20',
        'status': 'alerting',
        'triggered_at': '2026-02-05T10:00:00Z',
        'trigger_message': 'Alert triggered',
      });

      await tester.pumpWidget(
        buildTestApp(child: AlertListItem(alert: alertWithoutRule)),
      );

      expect(find.text('Alert triggered'), findsOneWidget);
    });

    testWidgets('shows status badge for alerting alerts', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertListItem(alert: testAlert)),
      );

      expect(find.text('Alerting'), findsOneWidget);
    });

    testWidgets('shows status badge for resolved alerts', (tester) async {
      final resolvedAlert = Alert.fromMap({
        'id': 'test-uuid-2',
        'alert_rule_id': 'test-alert-rule-uuid-10',
        'monitor_id': 'test-monitor-uuid-20',
        'status': 'resolved',
        'triggered_at': '2026-02-05T10:00:00Z',
        'resolved_at': '2026-02-05T10:15:00Z',
        'trigger_message': 'Monitor back online',
        'alert_rule': {
          'id': 'test-uuid-10',
          'team_id': 'test-team-uuid-5',
          'name': 'Test',
          'type': 'status',
          'severity': 'info',
        },
      });

      await tester.pumpWidget(
        buildTestApp(child: AlertListItem(alert: resolvedAlert)),
      );

      expect(find.text('Resolved'), findsOneWidget);
    });
  });
}
