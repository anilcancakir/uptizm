import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/controllers/analytics_controller.dart';
import 'package:uptizm/app/enums/metric_type.dart';
import 'package:uptizm/app/models/analytics_data_point.dart';
import 'package:uptizm/app/models/analytics_response.dart';
import 'package:uptizm/app/models/analytics_series.dart';
import 'package:uptizm/resources/views/components/analytics/date_range_selector.dart';
import 'package:uptizm/resources/views/components/analytics/metric_selector.dart';
import 'package:uptizm/resources/views/components/charts/multi_line_chart.dart';
import 'package:uptizm/resources/views/components/charts/status_timeline_chart.dart';
import 'package:uptizm/resources/views/monitors/monitor_analytics_view.dart';
import 'package:magic/magic.dart';

void main() {
  // Setup mock data
  final now = DateTime.now();
  final mockResponse = AnalyticsResponse(
    monitorId: 'test-monitor-uuid-1',
    dateFrom: now.subtract(const Duration(hours: 24)),
    dateTo: now,
    granularity: 'hourly',
    series: [
      AnalyticsSeries(
        metricKey: 'response_time',
        metricLabel: 'Response Time',
        metricType: MetricType.numeric,
        unit: 'ms',
        dataPoints: [AnalyticsDataPoint(timestamp: now, value: 100)],
      ),
      AnalyticsSeries(
        metricKey: 'api_status',
        metricLabel: 'API Status',
        metricType: MetricType.status,
        dataPoints: [AnalyticsDataPoint(timestamp: now, upCount: 1, total: 1)],
      ),
    ],
    summary: const AnalyticsSummary(
      totalChecks: 100,
      uptimePercent: 99.9,
      avgResponseTime: 120,
    ),
  );

  setUp(() {
    // Reset controller state
    final controller = AnalyticsController.instance;
    controller.analyticsNotifier.value = null;
    controller.selectedMetricsNotifier.value = [];
  });

  Widget buildTestWidget() {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(
        home: MediaQuery(
          data: const MediaQueryData(size: Size(1400, 900)),
          child: Scaffold(
            body: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: SizedBox(
                width: 1400, // Wide enough for desktop layout
                child: const MonitorAnalyticsView(),
              ),
            ),
          ),
        ),
      ),
    );
  }

  testWidgets('MonitorAnalyticsView shows loading state initially', (
    tester,
  ) async {
    await tester.pumpWidget(buildTestWidget());

    // Should show loading text or spinner
    expect(find.text('analytics.loading'), findsOneWidget);
  });

  testWidgets('MonitorAnalyticsView shows content when data loaded', (
    tester,
  ) async {
    // Pre-load data into controller
    final controller = AnalyticsController.instance;
    controller.analyticsNotifier.value = mockResponse;
    controller.selectedMetricsNotifier.value = ['response_time'];

    await tester.pumpWidget(buildTestWidget());
    await tester.pumpAndSettle();

    // Verify main components are present
    expect(find.byType(DateRangeSelector), findsOneWidget);
    expect(find.byType(MetricSelector), findsOneWidget);
    expect(find.byType(MultiLineChart), findsOneWidget);
    expect(find.byType(StatusTimelineChart), findsOneWidget);

    // Verify summary is shown
    expect(find.text('analytics.summary'), findsOneWidget);
    expect(find.text('99.9%'), findsOneWidget); // Uptime
    expect(find.text('120.0ms'), findsOneWidget); // Avg response
  });

  testWidgets('MonitorAnalyticsView shows empty state when no series', (
    tester,
  ) async {
    final controller = AnalyticsController.instance;
    controller.analyticsNotifier.value = AnalyticsResponse(
      monitorId: 'test-monitor-uuid-1',
      dateFrom: now,
      dateTo: now,
      granularity: 'hourly',
      series: [],
      summary: AnalyticsSummary.empty(),
    );

    await tester.pumpWidget(buildTestWidget());
    await tester.pumpAndSettle();

    expect(find.text('analytics.no_data'), findsOneWidget);
  });
}
