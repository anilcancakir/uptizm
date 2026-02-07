import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/alert.dart';
import 'package:uptizm/resources/views/components/alerts/active_alerts_panel.dart';
import 'package:uptizm/resources/views/components/alerts/alert_list_item.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('ActiveAlertsPanel', () {
    testWidgets('renders empty state when no alerts', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: const ActiveAlertsPanel(alerts: [])),
      );

      expect(find.text('No active alerts'), findsOneWidget);
    });

    testWidgets('renders alert count in header', (tester) async {
      final alerts = [
        Alert.fromMap({
          'id': 'test-uuid-1',
          'status': 'alerting',
          'triggered_at': '2026-02-05T10:00:00Z',
          'trigger_message': 'Alert 1',
          'alert_rule': {
            'id': 'test-uuid-1',
            'team_id': 'test-team-uuid-1',
            'name': 'Rule 1',
            'type': 'status',
            'severity': 'critical',
          },
        }),
        Alert.fromMap({
          'id': 'test-uuid-2',
          'status': 'alerting',
          'triggered_at': '2026-02-05T10:00:00Z',
          'trigger_message': 'Alert 2',
          'alert_rule': {
            'id': 'test-uuid-2',
            'team_id': 'test-team-uuid-1',
            'name': 'Rule 2',
            'type': 'threshold',
            'severity': 'warning',
          },
        }),
      ];

      await tester.pumpWidget(
        buildTestApp(child: ActiveAlertsPanel(alerts: alerts)),
      );

      // The count badge should show "2"
      expect(find.textContaining('2'), findsAtLeastNWidgets(1));
    });

    testWidgets('renders list of AlertListItem components', (tester) async {
      final alerts = [
        Alert.fromMap({
          'id': 'test-uuid-1',
          'status': 'alerting',
          'triggered_at': '2026-02-05T10:00:00Z',
          'trigger_message': 'Alert 1',
          'alert_rule': {
            'id': 'test-uuid-1',
            'team_id': 'test-team-uuid-1',
            'name': 'Rule 1',
            'type': 'status',
            'severity': 'critical',
          },
        }),
        Alert.fromMap({
          'id': 'test-uuid-2',
          'status': 'alerting',
          'triggered_at': '2026-02-05T10:00:00Z',
          'trigger_message': 'Alert 2',
          'alert_rule': {
            'id': 'test-uuid-2',
            'team_id': 'test-team-uuid-1',
            'name': 'Rule 2',
            'type': 'threshold',
            'severity': 'warning',
          },
        }),
      ];

      await tester.pumpWidget(
        buildTestApp(child: ActiveAlertsPanel(alerts: alerts)),
      );

      expect(find.byType(AlertListItem), findsNWidgets(2));
    });

    testWidgets('shows View All button', (tester) async {
      final alerts = [
        Alert.fromMap({
          'id': 'test-uuid-1',
          'status': 'alerting',
          'triggered_at': '2026-02-05T10:00:00Z',
          'trigger_message': 'Alert 1',
          'alert_rule': {
            'id': 'test-uuid-1',
            'team_id': 'test-team-uuid-1',
            'name': 'Rule 1',
            'type': 'status',
            'severity': 'critical',
          },
        }),
      ];

      await tester.pumpWidget(
        buildTestApp(
          child: ActiveAlertsPanel(alerts: alerts, onViewAll: () {}),
        ),
      );

      expect(find.text('View All'), findsOneWidget);
    });

    testWidgets('calls onViewAll when button tapped', (tester) async {
      bool viewAllCalled = false;

      final alerts = [
        Alert.fromMap({
          'id': 'test-uuid-1',
          'status': 'alerting',
          'triggered_at': '2026-02-05T10:00:00Z',
          'trigger_message': 'Alert 1',
          'alert_rule': {
            'id': 'test-uuid-1',
            'team_id': 'test-team-uuid-1',
            'name': 'Rule 1',
            'type': 'status',
            'severity': 'critical',
          },
        }),
      ];

      await tester.pumpWidget(
        buildTestApp(
          child: ActiveAlertsPanel(
            alerts: alerts,
            onViewAll: () => viewAllCalled = true,
          ),
        ),
      );

      await tester.tap(find.text('View All'));
      await tester.pump();

      expect(viewAllCalled, isTrue);
    });

    testWidgets('limits displayed alerts to maxDisplayed', (tester) async {
      final alerts = List.generate(
        10,
        (i) => Alert.fromMap({
          'id': i,
          'status': 'alerting',
          'triggered_at': '2026-02-05T10:00:00Z',
          'trigger_message': 'Alert $i',
          'alert_rule': {
            'id': i,
            'team_id': 'test-team-uuid-1',
            'name': 'Rule $i',
            'type': 'status',
            'severity': 'critical',
          },
        }),
      );

      await tester.pumpWidget(
        buildTestApp(child: ActiveAlertsPanel(alerts: alerts, maxDisplayed: 5)),
      );

      expect(find.byType(AlertListItem), findsNWidgets(5));
    });

    testWidgets('shows Active Alerts title', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: const ActiveAlertsPanel(alerts: [])),
      );

      expect(find.text('Active Alerts'), findsOneWidget);
    });
  });
}
