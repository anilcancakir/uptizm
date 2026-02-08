import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/alert_rule.dart';
import 'package:uptizm/resources/views/alerts/alert_rules_index_view.dart';
import 'package:uptizm/resources/views/components/alerts/alert_rule_list_item.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('AlertRulesIndexView', () {
    testWidgets('renders page title', (tester) async {
      await tester.pumpWidget(buildTestApp(child: const AlertRulesIndexView()));

      expect(find.text(trans('alerts.alert_rules')), findsOneWidget);
    });

    testWidgets('shows Add Rule button', (tester) async {
      tester.view.physicalSize = const Size(1024, 768);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() => tester.view.resetPhysicalSize());

      await tester.pumpWidget(
        buildTestApp(child: AlertRulesIndexView(onAddRule: () {})),
      );

      expect(find.text(trans('alerts.add_rule')), findsOneWidget);
    });

    testWidgets('renders empty state when no rules', (tester) async {
      await tester.pumpWidget(buildTestApp(child: const AlertRulesIndexView()));
      await tester.pumpAndSettle();

      expect(find.text(trans('alerts.no_rules')), findsOneWidget);
    });

    testWidgets('renders list of AlertRuleListItem when rules provided', (
      tester,
    ) async {
      final rules = [
        AlertRule.fromMap({
          'id': 'test-uuid-1',
          'team_id': 'test-team-uuid-10',
          'name': 'Rule 1',
          'type': 'status',
          'severity': 'critical',
        }),
        AlertRule.fromMap({
          'id': 'test-uuid-2',
          'team_id': 'test-team-uuid-10',
          'name': 'Rule 2',
          'type': 'threshold',
          'severity': 'warning',
        }),
      ];

      await tester.pumpWidget(
        buildTestApp(child: AlertRulesIndexView(initialRules: rules)),
      );
      await tester.pumpAndSettle();

      expect(find.byType(AlertRuleListItem), findsNWidgets(2));
    });

    testWidgets('shows loading state', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: const AlertRulesIndexView(isLoading: true)),
      );

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('calls onAddRule when Add Rule button tapped', (tester) async {
      tester.view.physicalSize = const Size(1024, 768);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() => tester.view.resetPhysicalSize());

      bool addRuleCalled = false;

      await tester.pumpWidget(
        buildTestApp(
          child: AlertRulesIndexView(onAddRule: () => addRuleCalled = true),
        ),
      );

      await tester.tap(find.text(trans('alerts.add_rule')));
      await tester.pump();

      expect(addRuleCalled, isTrue);
    });

    testWidgets('passes onEdit callback to AlertRuleListItem', (tester) async {
      bool editCalled = false;
      final rules = [
        AlertRule.fromMap({
          'id': 'test-uuid-1',
          'team_id': 'test-team-uuid-10',
          'name': 'Rule 1',
          'type': 'status',
          'severity': 'critical',
        }),
      ];

      await tester.pumpWidget(
        buildTestApp(
          child: AlertRulesIndexView(
            initialRules: rules,
            onEditRule: (rule) => editCalled = true,
          ),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byIcon(Icons.edit_outlined));
      await tester.pump();

      expect(editCalled, isTrue);
    });

    testWidgets('passes onDelete callback to AlertRuleListItem', (
      tester,
    ) async {
      bool deleteCalled = false;
      final rules = [
        AlertRule.fromMap({
          'id': 'test-uuid-1',
          'team_id': 'test-team-uuid-10',
          'name': 'Rule 1',
          'type': 'status',
          'severity': 'critical',
        }),
      ];

      await tester.pumpWidget(
        buildTestApp(
          child: AlertRulesIndexView(
            initialRules: rules,
            onDeleteRule: (rule) => deleteCalled = true,
          ),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byIcon(Icons.delete_outline));
      await tester.pump();

      expect(deleteCalled, isTrue);
    });
  });
}
