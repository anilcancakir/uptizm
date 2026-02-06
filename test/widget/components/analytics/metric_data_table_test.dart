import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/metric_type.dart';
import 'package:uptizm/app/models/analytics_data_point.dart';
import 'package:uptizm/app/models/analytics_series.dart';
import 'package:uptizm/resources/views/components/analytics/metric_data_table.dart';

class TestAppWrapper extends StatelessWidget {
  final Widget child;
  const TestAppWrapper({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(home: Scaffold(body: child)),
    );
  }
}

void main() {
  group('MetricDataTable', () {
    testWidgets('renders empty state', (tester) async {
      await tester.pumpWidget(
        const TestAppWrapper(child: MetricDataTable(series: [])),
      );
      await tester.pumpAndSettle();

      expect(find.textContaining('analytics.no_data'), findsOneWidget);
    });

    testWidgets('renders table with data', (tester) async {
      final now = DateTime(
        2024,
        1,
        1,
        12,
        0,
      ); // Fixed time for deterministic test
      final series = [
        AnalyticsSeries(
          metricKey: 'resp',
          metricLabel: 'Response',
          metricType: MetricType.numeric,
          unit: 'ms',
          dataPoints: [AnalyticsDataPoint(timestamp: now, value: 100)],
        ),
      ];

      await tester.pumpWidget(
        TestAppWrapper(child: MetricDataTable(series: series)),
      );
      await tester.pumpAndSettle();

      expect(find.byType(DataTable), findsOneWidget);
      expect(find.text('Response (ms)'), findsOneWidget); // Header
      expect(find.text('100.0'), findsOneWidget); // Value
      // Date formatting depends on locale, but checking partial match or standard formatting
      // DateFormat('MMM d, HH:mm') -> Jan 1, 12:00
      expect(find.textContaining('Jan 1'), findsOneWidget);
    });
  });
}
