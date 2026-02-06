import 'package:flutter/material.dart';
import 'package:fl_chart/fl_chart.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/metric_type.dart';
import 'package:uptizm/app/models/analytics_data_point.dart';
import 'package:uptizm/app/models/analytics_series.dart';
import 'package:uptizm/resources/views/components/charts/multi_line_chart.dart';

import 'package:magic/magic.dart';

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
  group('MultiLineChart', () {
    testWidgets('renders empty state when no series', (tester) async {
      await tester.pumpWidget(
        const TestAppWrapper(child: MultiLineChart(series: [])),
      );
      await tester.pumpAndSettle();
      expect(find.textContaining('analytics.no_data'), findsOneWidget);
    });

    testWidgets('renders chart with valid data', (tester) async {
      final now = DateTime.now();
      final series = AnalyticsSeries(
        metricKey: 'response_time',
        metricLabel: 'Response Time',
        metricType: MetricType.numeric,
        unit: 'ms',
        dataPoints: [
          AnalyticsDataPoint(
            timestamp: now.subtract(const Duration(hours: 2)),
            value: 100,
          ),
          AnalyticsDataPoint(
            timestamp: now.subtract(const Duration(hours: 1)),
            value: 150,
          ),
          AnalyticsDataPoint(timestamp: now, value: 120),
        ],
      );

      await tester.pumpWidget(
        TestAppWrapper(child: MultiLineChart(series: [series])),
      );
      await tester.pumpAndSettle();

      expect(find.byType(LineChart), findsOneWidget);
    });

    testWidgets('renders legend with metric labels', (tester) async {
      final now = DateTime.now();
      final series = AnalyticsSeries(
        metricKey: 'response_time',
        metricLabel: 'Response Time',
        metricType: MetricType.numeric,
        unit: 'ms',
        dataPoints: [AnalyticsDataPoint(timestamp: now, value: 100)],
      );

      await tester.pumpWidget(
        TestAppWrapper(
          child: MultiLineChart(series: [series], showLegend: true),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Response Time'), findsOneWidget);
    });
  });
}
