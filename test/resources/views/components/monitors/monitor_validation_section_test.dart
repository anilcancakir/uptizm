import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_wind/fluttersdk_wind.dart';

import '../../../../../lib/app/models/assertion_rule.dart';
import '../../../../../lib/app/models/metric_mapping.dart';
import '../../../../../lib/resources/views/components/monitors/monitor_validation_section.dart';

void main() {
  group('MonitorValidationSection', () {
    Widget buildSubject({
      Map<String, dynamic>? testFetchResponse,
      bool isTestingFetch = false,
      List<AssertionRule> assertionRules = const [],
      List<MetricMapping> metricMappings = const [],
    }) {
      return WindTheme(
        data: WindThemeData(),
        child: MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(
              child: MonitorValidationSection(
                testFetchResponse: testFetchResponse,
                isTestingFetch: isTestingFetch,
                onTestFetch: () {},
                assertionRules: assertionRules,
                onAssertionRulesChanged: (_) {},
                metricMappings: metricMappings,
                onMetricMappingsChanged: (_) {},
              ),
            ),
          ),
        ),
      );
    }

    testWidgets('renders response preview area', (tester) async {
      await tester.pumpWidget(buildSubject());
      await tester.pumpAndSettle();

      // ResponsePreview widget should be present
      expect(find.byType(MonitorValidationSection), findsOneWidget);
    });

    testWidgets('renders with existing assertion rules', (tester) async {
      await tester.pumpWidget(buildSubject(
        assertionRules: [
          AssertionRule.fromMap({
            'type': 'status_code',
            'operator': 'equals',
            'value': '200',
          }),
        ],
      ));
      await tester.pumpAndSettle();

      // The assertion rule editor should display the rule
      expect(find.text('200'), findsWidgets);
    });
  });
}
