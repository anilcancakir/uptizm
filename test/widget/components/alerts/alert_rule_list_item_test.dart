import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/alert_rule.dart';
import 'package:uptizm/resources/views/components/alerts/alert_rule_list_item.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('AlertRuleListItem', () {
    late AlertRule testRule;

    setUp(() {
      testRule = AlertRule.fromMap({
        'id': 'test-uuid-1',
        'team_id': 'test-team-uuid-10',
        'name': 'High Response Time',
        'type': 'threshold',
        'enabled': true,
        'metric_key': 'response_time',
        'operator': '>',
        'threshold_value': 5000.0,
        'severity': 'warning',
        'consecutive_checks': 2,
      });
    });

    testWidgets('renders rule name', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertRuleListItem(rule: testRule)),
      );

      expect(find.text('High Response Time'), findsOneWidget);
    });

    testWidgets('shows severity badge', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertRuleListItem(rule: testRule)),
      );

      expect(find.text('Warning'), findsOneWidget);
    });

    testWidgets('shows type label for threshold rules', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertRuleListItem(rule: testRule)),
      );

      expect(find.text('Threshold'), findsOneWidget);
    });

    testWidgets('shows threshold condition for threshold rules', (
      tester,
    ) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertRuleListItem(rule: testRule)),
      );

      expect(find.textContaining('response_time > 5000'), findsOneWidget);
    });

    testWidgets('shows metric key for anomaly rules', (tester) async {
      final anomalyRule = AlertRule.fromMap({
        'id': 'test-uuid-2',
        'team_id': 'test-team-uuid-10',
        'name': 'Order Anomaly',
        'type': 'anomaly',
        'metric_key': 'order_count',
        'severity': 'critical',
      });

      await tester.pumpWidget(
        buildTestApp(child: AlertRuleListItem(rule: anomalyRule)),
      );

      expect(find.text('Anomaly'), findsOneWidget);
      expect(find.textContaining('order_count'), findsOneWidget);
    });

    testWidgets('calls onEdit when edit button tapped', (tester) async {
      bool editCalled = false;

      await tester.pumpWidget(
        buildTestApp(
          child: AlertRuleListItem(
            rule: testRule,
            onEdit: () => editCalled = true,
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.edit_outlined));
      await tester.pump();

      expect(editCalled, isTrue);
    });

    testWidgets('calls onDelete when delete button tapped', (tester) async {
      bool deleteCalled = false;

      await tester.pumpWidget(
        buildTestApp(
          child: AlertRuleListItem(
            rule: testRule,
            onDelete: () => deleteCalled = true,
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.delete_outline));
      await tester.pump();

      expect(deleteCalled, isTrue);
    });

    testWidgets('shows team-level badge when monitor_id is null', (
      tester,
    ) async {
      final teamRule = AlertRule.fromMap({
        'id': 'test-uuid-4',
        'team_id': 'test-team-uuid-10',
        'monitor_id': null,
        'name': 'Team Default',
        'type': 'status',
        'severity': 'critical',
      });

      await tester.pumpWidget(
        buildTestApp(child: AlertRuleListItem(rule: teamRule)),
      );

      expect(find.text('Team Level'), findsOneWidget);
    });

    testWidgets('hides team-level badge for monitor-specific rules', (
      tester,
    ) async {
      // testRule has monitor_id = 20
      final monitorRule = AlertRule.fromMap({
        'id': 'test-uuid-5',
        'team_id': 'test-team-uuid-10',
        'monitor_id': 'test-monitor-uuid-20',
        'name': 'Monitor Override',
        'type': 'threshold',
        'severity': 'warning',
      });

      await tester.pumpWidget(
        buildTestApp(child: AlertRuleListItem(rule: monitorRule)),
      );

      expect(find.text('Team Level'), findsNothing);
    });
  });
}
