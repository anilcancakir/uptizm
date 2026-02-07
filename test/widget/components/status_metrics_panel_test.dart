import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/monitor_metric_value.dart';
import 'package:uptizm/resources/views/components/monitors/status_metrics_panel.dart';
import 'package:uptizm/resources/views/components/monitors/status_metric_badge.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('StatusMetricsPanel', () {
    testWidgets('renders multiple StatusMetricBadge components', (
      tester,
    ) async {
      final metrics = [
        MonitorMetricValue.fromMap({
          'id': 'test-uuid-1',
          'monitor_id': 'test-monitor-uuid-10',
          'check_id': 'test-check-uuid-100',
          'metric_key': 'is_healthy',
          'metric_label': 'Service Health',
          'status_value': 'up',
          'recorded_at': '2026-02-04T12:00:00.000000Z',
        }),
        MonitorMetricValue.fromMap({
          'id': 'test-uuid-2',
          'monitor_id': 'test-monitor-uuid-10',
          'check_id': 'test-check-uuid-100',
          'metric_key': 'db_connected',
          'metric_label': 'Database',
          'status_value': 'down',
          'recorded_at': '2026-02-04T12:00:00.000000Z',
        }),
      ];

      await tester.pumpWidget(
        buildTestApp(child: StatusMetricsPanel(metrics: metrics)),
      );

      expect(find.byType(StatusMetricBadge), findsNWidgets(2));
      // Labels are uppercased in StatusMetricBadge
      expect(find.text('SERVICE HEALTH'), findsOneWidget);
      expect(find.text('DATABASE'), findsOneWidget);
    });

    testWidgets('shows empty state when no status metrics', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: const StatusMetricsPanel(metrics: [])),
      );

      expect(find.byType(StatusMetricBadge), findsNothing);
    });

    testWidgets('renders with title when provided', (tester) async {
      final metrics = [
        MonitorMetricValue.fromMap({
          'id': 'test-uuid-1',
          'monitor_id': 'test-monitor-uuid-10',
          'check_id': 'test-check-uuid-100',
          'metric_key': 'is_healthy',
          'metric_label': 'Health',
          'status_value': 'up',
          'recorded_at': '2026-02-04T12:00:00.000000Z',
        }),
      ];

      await tester.pumpWidget(
        buildTestApp(
          child: StatusMetricsPanel(metrics: metrics, title: 'Status Metrics'),
        ),
      );

      // Title is uppercased in StatusMetricsPanel header
      expect(find.text('STATUS METRICS'), findsOneWidget);
    });
  });
}
