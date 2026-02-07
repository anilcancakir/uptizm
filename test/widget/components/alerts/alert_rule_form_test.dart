import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/alert_operator.dart';
import 'package:uptizm/app/enums/alert_rule_type.dart';
import 'package:uptizm/app/enums/alert_severity.dart';
import 'package:uptizm/app/models/alert_rule.dart';
import 'package:uptizm/resources/views/components/alerts/alert_rule_form.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(
      home: Scaffold(body: SingleChildScrollView(child: child)),
    ),
  );
}

void main() {
  group('AlertRuleForm', () {
    testWidgets('renders name input', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertRuleForm(onSubmit: (rule) {})),
      );

      expect(find.text('Rule Name'), findsOneWidget);
    });

    testWidgets('renders type selector', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertRuleForm(onSubmit: (rule) {})),
      );

      expect(find.text('Alert Type'), findsOneWidget);
    });

    testWidgets('renders severity selector', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertRuleForm(onSubmit: (rule) {})),
      );

      expect(find.text('Severity'), findsOneWidget);
    });

    testWidgets('shows metric selector for threshold type', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: AlertRuleForm(
            onSubmit: (rule) {},
            initialType: AlertRuleType.threshold,
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Metric'), findsOneWidget);
    });

    testWidgets('shows operator selector for threshold type', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: AlertRuleForm(
            onSubmit: (rule) {},
            initialType: AlertRuleType.threshold,
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Operator'), findsOneWidget);
    });

    testWidgets('shows threshold value input for threshold type', (
      tester,
    ) async {
      await tester.pumpWidget(
        buildTestApp(
          child: AlertRuleForm(
            onSubmit: (rule) {},
            initialType: AlertRuleType.threshold,
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Threshold Value'), findsOneWidget);
    });

    testWidgets('pre-fills form with existing rule data', (tester) async {
      final existingRule = AlertRule.fromMap({
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

      await tester.pumpWidget(
        buildTestApp(
          child: AlertRuleForm(initialRule: existingRule, onSubmit: (rule) {}),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('High Response Time'), findsOneWidget);
    });

    testWidgets('calls onSubmit with form data when submitted', (tester) async {
      AlertRule? submittedRule;

      await tester.pumpWidget(
        buildTestApp(
          child: AlertRuleForm(
            onSubmit: (rule) {
              submittedRule = rule;
            },
          ),
        ),
      );
      await tester.pumpAndSettle();

      // Fill name - find TextField widget inside WFormInput
      final nameField = find.byType(TextField).first;
      await tester.enterText(nameField, 'Test Alert');
      await tester.pumpAndSettle();

      // Submit
      await tester.tap(find.text('Save Alert Rule'));
      await tester.pumpAndSettle();

      expect(submittedRule, isNotNull);
      expect(submittedRule!.name, 'Test Alert');
    });

    testWidgets('validates required fields', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertRuleForm(onSubmit: (rule) {})),
      );
      await tester.pumpAndSettle();

      // Submit without filling fields
      await tester.tap(find.text('Save Alert Rule'));
      await tester.pumpAndSettle();

      // Should show validation errors
      expect(find.text('Rule name is required'), findsOneWidget);
    });

    testWidgets('shows consecutive checks input', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: AlertRuleForm(onSubmit: (rule) {})),
      );
      await tester.pumpAndSettle();

      expect(find.text('Consecutive Checks'), findsOneWidget);
    });
  });
}
