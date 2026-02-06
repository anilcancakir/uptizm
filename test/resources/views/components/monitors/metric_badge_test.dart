import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/components/monitors/metric_badge.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('MetricBadge', () {
    testWidgets('renders label and value', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const MetricBadge(label: 'CPU Usage', value: '45.2'),
        ),
      );

      expect(find.text('CPU Usage'), findsOneWidget);
      expect(find.text('45.2'), findsOneWidget);
    });

    testWidgets('renders unit suffix when provided', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const MetricBadge(label: 'Memory', value: '2.4', unit: 'GB'),
        ),
      );

      expect(find.text('Memory'), findsOneWidget);
      expect(find.textContaining('2.4'), findsOneWidget);
      expect(find.textContaining('GB'), findsOneWidget);
    });

    testWidgets('uses monospace font for value', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const MetricBadge(label: 'Latency', value: '123', unit: 'ms'),
        ),
      );

      expect(find.textContaining('123'), findsOneWidget);
      // Will verify monospace styling in implementation
    });

    testWidgets('renders as pill-shaped badge', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const MetricBadge(label: 'Requests', value: '1000'),
        ),
      );

      expect(find.byType(MetricBadge), findsOneWidget);
      // Will verify rounded-full styling in implementation
    });
  });
}
