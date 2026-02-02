import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_wind/fluttersdk_wind.dart';
import 'package:uptizm/app/enums/assertion_operator.dart';
import 'package:uptizm/app/enums/assertion_type.dart';
import 'package:uptizm/app/models/assertion_rule.dart';
import 'package:uptizm/resources/views/components/assertion_rule_editor.dart';

void main() {
  Widget wrapWithTheme(Widget child) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(
        home: Scaffold(
          body: child,
        ),
      ),
    );
  }

  group('AssertionRuleEditor', () {
    testWidgets('renders empty state with Add button', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          AssertionRuleEditor(
            rules: const [],
            onChanged: (_) {},
          ),
        ),
      );

      expect(find.text('Add Assertion Rule'), findsOneWidget);
      expect(find.byType(WSelect), findsNothing);
    });

    testWidgets('renders existing rules', (tester) async {
      final rules = [
        AssertionRule(
          type: AssertionType.statusCode,
          operator: AssertionOperator.equals,
          value: '200',
        ),
        AssertionRule(
          type: AssertionType.bodyJsonPath,
          operator: AssertionOperator.equals,
          value: 'success',
          path: 'data.status',
        ),
      ];

      await tester.pumpWidget(
        wrapWithTheme(
          AssertionRuleEditor(
            rules: rules,
            onChanged: (_) {},
          ),
        ),
      );

      // Should have rule summaries displayed
      expect(find.text('status_code == 200'), findsOneWidget);
      expect(find.text('data.status == success'), findsOneWidget);

      // Should have TextFields for value input (one per rule)
      expect(find.widgetWithText(TextField, 'Value'), findsNWidgets(2));
    });

    testWidgets('adds new rule when Add button clicked', (tester) async {
      List<AssertionRule> capturedRules = [];

      await tester.pumpWidget(
        wrapWithTheme(
          AssertionRuleEditor(
            rules: const [],
            onChanged: (rules) => capturedRules = rules,
          ),
        ),
      );

      // Click Add button
      await tester.tap(find.text('Add Assertion Rule'));
      await tester.pumpAndSettle();

      // Should have one rule with default values
      expect(capturedRules.length, 1);
      expect(capturedRules[0].type, AssertionType.statusCode);
      expect(capturedRules[0].operator, AssertionOperator.equals);
      expect(capturedRules[0].value, '');
    });

    testWidgets('removes rule when delete button clicked', (tester) async {
      List<AssertionRule> capturedRules = [];

      final rules = [
        AssertionRule(
          type: AssertionType.statusCode,
          operator: AssertionOperator.equals,
          value: '200',
        ),
        AssertionRule(
          type: AssertionType.responseTime,
          operator: AssertionOperator.lessThan,
          value: '500',
        ),
      ];

      await tester.pumpWidget(
        wrapWithTheme(
          AssertionRuleEditor(
            rules: rules,
            onChanged: (rules) => capturedRules = rules,
          ),
        ),
      );

      // Find and tap the first delete button
      final deleteButtons = find.byIcon(Icons.close);
      await tester.tap(deleteButtons.first);
      await tester.pumpAndSettle();

      expect(capturedRules.length, 1);
      expect(capturedRules[0].type, AssertionType.responseTime);
    });

    testWidgets('shows path input only for bodyJsonPath type', (tester) async {
      final rules = [
        AssertionRule(
          type: AssertionType.bodyJsonPath,
          operator: AssertionOperator.equals,
          value: 'success',
          path: 'data.status',
        ),
        AssertionRule(
          type: AssertionType.statusCode,
          operator: AssertionOperator.equals,
          value: '200',
        ),
      ];

      await tester.pumpWidget(
        wrapWithTheme(
          AssertionRuleEditor(
            rules: rules,
            onChanged: (_) {},
          ),
        ),
      );

      // First rule should have path input, second should not
      expect(find.widgetWithText(TextField, 'data.status'), findsOneWidget);
    });

    testWidgets('updates rule when type is changed', (tester) async {
      List<AssertionRule> capturedRules = [];

      final rules = [
        AssertionRule(
          type: AssertionType.statusCode,
          operator: AssertionOperator.equals,
          value: '200',
        ),
      ];

      await tester.pumpWidget(
        wrapWithTheme(
          AssertionRuleEditor(
            rules: rules,
            onChanged: (rules) => capturedRules = rules,
          ),
        ),
      );

      // Find type select and change it
      final typeSelect = find.byType(WSelect<AssertionType>).first;
      await tester.tap(typeSelect);
      await tester.pumpAndSettle();

      // Select bodyJsonPath option
      await tester.tap(find.text('JSON Path').last);
      await tester.pumpAndSettle();

      expect(capturedRules[0].type, AssertionType.bodyJsonPath);
    });

    testWidgets('displays rule summary correctly', (tester) async {
      final rules = [
        AssertionRule(
          type: AssertionType.bodyContains,
          operator: AssertionOperator.contains,
          value: 'success',
        ),
      ];

      await tester.pumpWidget(
        wrapWithTheme(
          AssertionRuleEditor(
            rules: rules,
            onChanged: (_) {},
          ),
        ),
      );

      // Should display formatted summary using toDisplayString()
      expect(find.text('body contains success'), findsOneWidget);
    });
  });
}
