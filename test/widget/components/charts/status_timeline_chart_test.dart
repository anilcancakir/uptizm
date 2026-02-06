import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/metric_type.dart';
import 'package:uptizm/app/models/analytics_data_point.dart';
import 'package:uptizm/app/models/analytics_series.dart';
import 'package:uptizm/resources/views/components/charts/status_timeline_chart.dart';

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
  group('StatusTimelineChart', () {
    testWidgets('renders empty state when no data points', (tester) async {
      final series = AnalyticsSeries(
        metricKey: 'status',
        metricLabel: 'Status',
        metricType: MetricType.status,
        dataPoints: [],
      );

      await tester.pumpWidget(
        TestAppWrapper(child: StatusTimelineChart(statusSeries: series)),
      );
      await tester.pumpAndSettle();

      expect(find.textContaining('analytics.no_data'), findsOneWidget);
    });

    testWidgets('renders chart with valid status data', (tester) async {
      final now = DateTime.now();
      final series = AnalyticsSeries(
        metricKey: 'status',
        metricLabel: 'Status',
        metricType: MetricType.status,
        dataPoints: [
          AnalyticsDataPoint(
            timestamp: now,
            value: 100,
            upCount: 1,
            downCount: 0,
            total: 1,
          ),
        ],
      );

      await tester.pumpWidget(
        TestAppWrapper(child: StatusTimelineChart(statusSeries: series)),
      );
      await tester.pumpAndSettle();

      expect(find.byType(BarChart), findsOneWidget);
    });
  });
}
