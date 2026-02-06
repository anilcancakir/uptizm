import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/metric_status_value.dart';
import 'package:uptizm/resources/views/components/monitors/status_metric_badge.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('StatusMetricBadge', () {
    testWidgets('renders UP badge with green styling for up status', (
      tester,
    ) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatusMetricBadge(
            label: 'Service Health',
            status: MetricStatusValue.up,
          ),
        ),
      );

      expect(find.text('UP'), findsOneWidget);
      expect(find.text('SERVICE HEALTH'), findsOneWidget);
    });

    testWidgets('renders DOWN badge with red styling for down status', (
      tester,
    ) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatusMetricBadge(
            label: 'Database Connection',
            status: MetricStatusValue.down,
          ),
        ),
      );

      expect(find.text('DOWN'), findsOneWidget);
      expect(find.text('DATABASE CONNECTION'), findsOneWidget);
    });

    testWidgets('renders UNKNOWN badge for unknown status', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatusMetricBadge(
            label: 'API Status',
            status: MetricStatusValue.unknown,
          ),
        ),
      );

      expect(find.text('UNKNOWN'), findsOneWidget);
      expect(find.text('API STATUS'), findsOneWidget);
    });

    testWidgets('renders with null status gracefully', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const StatusMetricBadge(label: 'Test Metric', status: null),
        ),
      );

      expect(find.text('TEST METRIC'), findsOneWidget);
      expect(find.text('-'), findsOneWidget);
    });
  });
}
